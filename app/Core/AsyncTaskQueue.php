<?php

namespace App\Core;

/**
 * 异步任务队列（基于数据库表 async_tasks）。
 *
 * 旧实现使用 JSON 文件 + 进程内数组，存在任务丢失、并发覆盖、
 * 进程崩溃后 processing 任务永久丢失等问题。
 *
 * 现改用 async_tasks 表持久化：
 * - enqueue: INSERT 一行 pending 任务
 * - dequeue: 在事务内 SELECT pending → UPDATE 为 processing（记录 processing_since）
 * - complete: DELETE 已完成的 processing 任务
 * - fail: 重置为 pending（重试次数 < 上限时）或标记 failed
 * - visibility timeout: processing_since 超过 300 秒视为崩溃残留，重置为 pending
 *
 * 这样即使进程崩溃，超时的 processing 任务会被下一个 process() 重新捡起执行。
 */
class AsyncTaskQueue
{
    private static $instance = null;
    private $db;
    private $maxQueueSize = 1000;
    private $batchSize = 10;

    /** 可见性超时（秒）：processing 任务超过该时长视为崩溃残留，重置为 pending */
    private int $visibilityTimeout = 300;

    /** 最大重试次数：超过后标记为 failed */
    private int $maxRetries = 3;

    private function __construct()
    {
        $this->db = Database::getInstance();
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    /**
     * 设置可见性超时（秒）。可在配置阶段调用。
     */
    public function setVisibilityTimeout(int $seconds): void
    {
        $this->visibilityTimeout = $seconds > 0 ? $seconds : 300;
    }

    /**
     * 入队一个任务。
     *
     * @param string $taskType 任务类型
     * @param array $data 任务数据（将 JSON 序列化存储）
     * @param int $priority 优先级（数值越大越优先）
     * @return string 任务 id（数据库自增 id 的字符串形式）
     */
    public function enqueue($taskType, $data, $priority = 5)
    {
        // 控制队列长度：pending 任务过多时丢弃最旧的低优先级任务
        $pendingCount = (int)$this->db->fetch(
            "SELECT COUNT(*) AS cnt FROM async_tasks WHERE status = 'pending'"
        )['cnt'] ?? 0;

        if ($pendingCount >= $this->maxQueueSize) {
            // 删除最旧的一条 pending 任务
            $oldest = $this->db->fetch(
                "SELECT id FROM async_tasks WHERE status = 'pending'
                 ORDER BY priority ASC, created_at ASC LIMIT 1"
            );
            if ($oldest) {
                $this->db->delete('async_tasks', 'id = ?', [$oldest['id']]);
            }
        }

        $id = $this->db->insert('async_tasks', [
            'type' => $taskType,
            'payload' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
            'priority' => (int)$priority,
            'retries' => 0,
            'created_at' => time(),
            'processing_since' => null,
            'error' => '',
        ]);

        return (string)$id;
    }

    /**
     * 原子地取出若干 pending 任务并标记为 processing。
     *
     * 使用 BEGIN IMMEDIATE 事务保证 SELECT + UPDATE 的原子性，
     * 避免多进程并发取到同一任务。
     *
     * @param int $count 取出数量
     * @return array 任务数组，每个元素含 id/type/data 等字段
     */
    public function dequeue($count = 1)
    {
        $guard = ConcurrencyGuard::getInstance();
        $now = time();

        return $guard->transactionImmediate(function () use ($count, $now) {
            // 按优先级降序、创建时间升序取出 pending 任务
            $rows = $this->db->fetchAll(
                "SELECT id, type, payload, status, priority, retries, created_at, processing_since, error
                 FROM async_tasks
                 WHERE status = 'pending'
                 ORDER BY priority DESC, created_at ASC
                 LIMIT ?",
                [(int)$count]
            );

            if (empty($rows)) {
                return [];
            }

            $ids = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // 原子标记为 processing
            $params = array_merge([$now], $ids);
            $this->db->query(
                "UPDATE async_tasks SET status = 'processing', processing_since = ?
                 WHERE id IN ($placeholders)",
                $params
            );

            // 转换为兼容旧接口的结构（payload → data）
            $tasks = [];
            foreach ($rows as $row) {
                $tasks[] = [
                    'id' => (string)$row['id'],
                    'type' => $row['type'],
                    'data' => json_decode($row['payload'], true) ?: [],
                    'priority' => (int)$row['priority'],
                    'created_at' => (int)$row['created_at'],
                    'status' => $row['status'],
                    'retries' => (int)$row['retries'],
                ];
            }

            return $tasks;
        });
    }

    /**
     * 标记任务完成并从队列删除。
     *
     * @param mixed $taskId 任务 id
     * @param mixed $result 结果（新实现不持久化，仅为接口兼容）
     */
    public function complete($taskId, $result = null)
    {
        $this->db->delete(
            'async_tasks',
            'id = ? AND status = ?',
            [(int)$taskId, 'processing']
        );
    }

    /**
     * 标记任务失败：递增重试次数，未超上限则重置为 pending，否则标记 failed。
     *
     * @param mixed $taskId 任务 id
     * @param string|null $error 错误信息
     */
    public function fail($taskId, $error = null)
    {
        $task = $this->db->fetch(
            "SELECT id, retries FROM async_tasks WHERE id = ?",
            [(int)$taskId]
        );

        if (!$task) {
            return;
        }

        $retries = (int)$task['retries'] + 1;

        if ($retries >= $this->maxRetries) {
            $this->db->update(
                'async_tasks',
                [
                    'status' => 'failed',
                    'retries' => $retries,
                    'error' => (string)$error,
                    'processing_since' => null,
                ],
                'id = ?',
                [(int)$taskId]
            );
        } else {
            $this->db->update(
                'async_tasks',
                [
                    'status' => 'pending',
                    'retries' => $retries,
                    'error' => (string)$error,
                    'processing_since' => null,
                ],
                'id = ?',
                [(int)$taskId]
            );
        }
    }

    /**
     * 回收超时的 processing 任务（visibility timeout 机制）。
     *
     * 进程崩溃后 processing 任务会停留在数据库中，超过 visibilityTimeout
     * 秒后视为失败残留，重置为 pending 等待重新执行。
     */
    private function reclaimStaleTasks(): void
    {
        $threshold = time() - $this->visibilityTimeout;

        // 先查出超时的 ai_agent_task（需要 task_id 和 payload 中的 stream_mode）
        // 这些任务 Worker 已崩溃，需写终态事件通知前端
        $staleAgentTasks = $this->db->fetchAll(
            "SELECT id, payload FROM async_tasks
             WHERE status = 'processing' AND processing_since IS NOT NULL
               AND processing_since < ? AND type = 'ai_agent_task'",
            [$threshold]
        );

        if (!empty($staleAgentTasks)) {
            $store = new \App\Core\StreamProgressStore();
            $logger = \App\Core\AsyncLogger::getInstance();
            foreach ($staleAgentTasks as $stale) {
                $payload = json_decode($stale['payload'] ?? '{}', true) ?: [];
                $taskId = (string)$stale['id'];
                $streamMode = !empty($payload['stream_mode']);
                $sessionId = $payload['session_id'] ?? '';
                $userId = (int)($payload['user_id'] ?? 0);

                // stream 模式：写 error 事件让前端 SSE 能收到终态
                if ($streamMode) {
                    $store->appendEvent($taskId, 'error', [
                        'message' => 'Worker 超时未响应，任务已自动终止'
                    ]);
                }

                // 更新 ai_agent_progress 状态为 failed
                if ($userId > 0 && $sessionId !== '') {
                    $this->db->update(
                        'ai_agent_progress',
                        [
                            'status' => 'failed',
                            'error_message' => 'Worker 超时未响应，任务已自动终止',
                            'updated_at' => time(),
                        ],
                        'task_id = ?',
                        [$taskId]
                    );
                }

                $logger->warning("[AsyncTaskQueue] Reclaimed stale ai_agent_task {$taskId}");
            }
        }

        // 重置所有超时的 processing 任务为 failed（非 ai_agent_task）或删除（ai_agent_task 已写终态）
        // ai_agent_task 的 retries 通常应到达上限标记 failed，这里统一 reset 让 fail() 机制处理
        $this->db->query(
            "UPDATE async_tasks
             SET status = 'pending', processing_since = NULL
             WHERE status = 'processing' AND processing_since IS NOT NULL
               AND processing_since < ?",
            [$threshold]
        );
    }

    /**
     * 处理一批任务：先回收超时任务，再 dequeue 执行。
     */
    public function process()
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // 回收崩溃进程遗留的 processing 任务
        $this->reclaimStaleTasks();

        $tasks = $this->dequeue($this->batchSize);
        $logger = AsyncLogger::getInstance();

        foreach ($tasks as $task) {
            try {
                $result = $this->executeTask($task);
                $this->complete($task['id'], $result);
                $logger->info("Task {$task['id']} completed", ['type' => $task['type']]);
            } catch (\Exception $e) {
                $this->fail($task['id'], $e->getMessage());
                $logger->error("Task {$task['id']} failed: " . $e->getMessage());
            }
        }
    }

    private function executeTask($task)
    {
        switch ($task['type']) {
            case 'hash_calculation':
                return $this->calculateHash($task['data']);

            case 'thumbnail_generation':
                return $this->generateThumbnail($task['data']);

            case 'file_compression':
                return $this->compressFile($task['data']);

            case 'log_flush':
                return AsyncLogger::getInstance()->flush();

            case 'cache_cleanup':
                return $this->cleanupCache($task['data']);

            default:
                throw new \Exception("Unknown task type: {$task['type']}");
        }
    }

    private function calculateHash($data)
    {
        $filePath = $data['file_path'];
        $fileId = $data['file_id'];

        if (!file_exists($filePath)) {
            return false;
        }

        $hash = hash_file('sha256', $filePath);
        if ($hash) {
            $db = Database::getInstance();
            $db->update('files', ['content_hash' => $hash, 'updated_at' => time()], 'id = ?', [$fileId]);
            return $hash;
        }

        return false;
    }

    private function generateThumbnail($data)
    {
        $fileId = $data['file_id'];
        $filePath = $data['file_path'];
        $ext = $data['ext'];

        $thumbnailService = new \App\Services\ThumbnailService();
        $cacheKey = md5($fileId . '_' . filesize($filePath));
        $result = $thumbnailService->generate($filePath, $ext, $cacheKey);

        return $result !== null;
    }

    private function compressFile($data)
    {
        $filePath = $data['file_path'];
        $outputPath = $data['output_path'];

        if (!file_exists($filePath)) {
            return false;
        }

        $zip = new \ZipArchive();
        if ($zip->open($outputPath, \ZipArchive::CREATE) === true) {
            $zip->addFile($filePath, basename($filePath));
            $zip->close();
            return true;
        }

        return false;
    }

    private function cleanupCache($data)
    {
        $cacheDir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cache';
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . DIRECTORY_SEPARATOR . '*');
            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < (time() - 86400 * 7)) {
                    @unlink($file);
                }
            }
        }
        return true;
    }

    /**
     * 获取队列统计信息。
     *
     * 新实现中 completed 任务已删除，故 completed 始终为 0。
     */
    public function getQueueStats()
    {
        $stats = [
            'total' => 0,
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
        ];

        $rows = $this->db->fetchAll(
            "SELECT status, COUNT(*) AS cnt FROM async_tasks GROUP BY status"
        );

        foreach ($rows as $row) {
            $status = $row['status'];
            if (isset($stats[$status])) {
                $stats[$status] = (int)$row['cnt'];
            }
            $stats['total'] += (int)$row['cnt'];
        }

        return $stats;
    }

    /**
     * 清理已完成/失败的任务。
     *
     * 新实现中 completed 任务即时删除，这里仅清理 failed 任务。
     */
    public function clearCompleted()
    {
        $this->db->delete('async_tasks', "status = 'failed'");
    }

    /**
     * 供 Worker 调用的公开任务执行方法。
     *
     * WorkerProcess::executeTask() 中 ai_agent_task 之外的任务委派至此。
     * 内部委托 executeTask()（private），避免在外部重新实现任务分发逻辑。
     *
     * @param array $task 任务数组（来自 dequeue 的结构）
     * @return mixed 任务执行结果
     */
    public function processTaskFromWorker(array $task)
    {
        return $this->executeTask($task);
    }

    /**
     * 入队 AI Agent 后台任务（高优先级）。
     *
     * @param int $userId 用户ID
     * @param string $sessionId 会话ID
     * @param array $messages 消息列表
     * @param array|null $context 上下文
     * @param bool $streamMode 是否为流式模式（Worker 执行时写细粒度进度文件）
     * @return string 任务ID
     */
    public function enqueueAgentTask(int $userId, string $sessionId, array $messages, ?array $context = null, bool $streamMode = false): string
    {
        $data = [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'messages' => $messages,
            'context' => $context,
            'stream_mode' => $streamMode,
        ];

        return $this->enqueue('ai_agent_task', $data, 8); // 优先级 8（高于默认 5）
    }
}
