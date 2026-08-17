<?php

namespace App\Services;

use App\Core\Database;
use App\Core\AsyncTaskQueue;
use App\Core\NotificationService;
use App\Core\AsyncLogger;
use App\Core\StreamProgressStore;

/**
 * AI Agent 后台执行引擎。
 *
 * 在 WorkerProcess 中消费 ai_agent_task 类型的任务。
 * 核心能力：
 * - enqueueTask(): 入队后台任务 + 写 ai_agent_progress 初始记录
 * - executeTask(): Worker 调用，模拟 $_SESSION，运行 Agent 循环，写进度/完成/通知
 * - getProgress(): 查询任务进度
 * - listTasks(): 获取用户后台任务列表
 *
 * 关键设计：
 * - Worker 进程中无 $_SESSION，需手动模拟 user_id / username
 * - outputCallback 重定向为写 ai_agent_progress 表（节流：每 3 秒最多一次）
 * - 完成后写 NotificationService 通知
 * - 调用 chatStream 时传入 autoConfirm = true，后台模式自动确认危险操作
 */
class AIAgentBackgroundService
{
    private $db;
    private $logger;

    /** 进度写入节流间隔（秒） */
    private int $progressThrottle = 3;

    /** 上次进度写入时间戳 */
    private int $lastProgressWrite = 0;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->logger = AsyncLogger::getInstance();
    }

    /**
     * 入队后台 AI Agent 任务。
     *
     * @param int $userId 用户ID
     * @param string $sessionId 会话ID
     * @param array $messages 消息列表
     * @param array|null $context 上下文
     * @param bool $streamMode 是否为 stream 模式（写入 data.stream_mode，供 Worker 细粒度写入 StreamProgressStore）
     * @return string 任务ID
     */
    public function enqueueTask(int $userId, string $sessionId, array $messages, ?array $context = null, bool $streamMode = false): string
    {
        $queue = AsyncTaskQueue::getInstance();
        $taskId = $queue->enqueueAgentTask($userId, $sessionId, $messages, $context, $streamMode);

        // 写入进度表初始记录
        $now = time();
        $this->db->insert('ai_agent_progress', [
            'task_id' => $taskId,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'status' => 'queued',
            'current_tool' => '',
            'iteration' => 0,
            'progress_percent' => 0,
            'result_summary' => '',
            'error_message' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->logger->info("[AIBgService] Task {$taskId} enqueued for user {$userId}");

        return $taskId;
    }

    /**
     * Worker 调用：执行后台 AI Agent 任务。
     *
     * @param array $task 任务数组（来自 AsyncTaskQueue::dequeue）
     * @return array 执行结果
     */
    public function executeTask(array $task): array
    {
        $data = $task['data'] ?? [];
        $taskId = $task['id'];
        $userId = (int)($data['user_id'] ?? 0);
        $sessionId = $data['session_id'] ?? '';
        $messages = $data['messages'] ?? [];
        $context = $data['context'] ?? null;

        // 检测 stream 模式：仅在 stream 模式下创建 StreamProgressStore（非 stream 模式为 null，不影响现有路径）
        $streamMode = !empty($data['stream_mode']);
        $streamStore = $streamMode ? new StreamProgressStore() : null;

        if ($userId <= 0) {
            $this->updateProgress($taskId, 'failed', '', 0, 0, '', 'Invalid user_id');
            if ($streamStore) {
                $streamStore->appendEvent($taskId, 'error', ['message' => 'Invalid user_id']);
            }
            return ['success' => false, 'error' => 'Invalid user_id'];
        }

        // 模拟 $_SESSION（AIAgentService::chatStream 和 buildSystemPrompt 依赖）
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $this->getUsername($userId);

        // 更新进度为 running
        $this->updateProgress($taskId, 'running', '', 0, 10);

        // stream 模式：任务开始时写 stream_start 事件
        if ($streamStore) {
            $streamStore->appendEvent($taskId, 'stream_start', ['task_id' => $taskId, 'session_id' => $sessionId]);
        }

        $this->logger->info("[AIBgService] Task {$taskId} starting for user {$userId}");

        try {
            $service = new AIAgentService();
            $allToolResults = [];
            $resultSummary = '';
            $failed = false;

            $service->chatStream($messages, function ($type, $data) use ($taskId, &$allToolResults, &$resultSummary, &$failed, $streamStore) {
                // 检查任务是否被取消（前端 POST cancelTask 设置 cancel_requested=1）
                if ($this->isCancelled($taskId)) {
                    throw new \RuntimeException('任务已取消');
                }

                // === stream 模式：细粒度写入进度文件（无节流，对所有 type 生效，包括 token/content/tool_progress 等细粒度事件）===
                if ($streamStore) {
                    $streamStore->appendEvent($taskId, $type, $data);
                }

                // === 粗粒度更新 ai_agent_progress 表（保留现有逻辑，3 秒节流）===
                switch ($type) {
                    case 'tool_start':
                        $toolName = $data['name'] ?? '';
                        $this->throttledUpdateProgress($taskId, 'running', $toolName);
                        break;

                    case 'tool_result':
                        $allToolResults[] = $data;
                        break;

                    case 'done':
                        $resultSummary = $data['message'] ?? '';
                        $this->updateProgress($taskId, 'completed', '', null, 100, mb_substr($resultSummary, 0, 500));
                        break;

                    case 'error':
                        $failed = true;
                        $errorMsg = $data['message'] ?? 'Unknown error';
                        $this->updateProgress($taskId, 'failed', '', null, 0, '', $errorMsg);
                        break;
                }
            }, $context, $sessionId, false, true); // autoConfirm = true

            // 完成后通知用户
            $ns = new NotificationService();
            if ($failed) {
                $ns->notify($userId, 'agent_failed', 'AI 后台任务失败', mb_substr($resultSummary ?: '未知错误', 0, 200), $sessionId);
            } else {
                $ns->notify($userId, 'agent_complete', 'AI 后台任务完成', mb_substr($resultSummary, 0, 200), $sessionId);
            }

            $this->logger->info("[AIBgService] Task {$taskId} completed");

            return ['success' => true, 'summary' => $resultSummary];
        } catch (\Throwable $e) {
            $errMsg = $e->getMessage();

            // 先写 error 事件到 stream 文件，再更新 DB status
            // 顺序很重要：确保 SSE 端点能在检测到 status=failed 前读到 error 事件
            if ($streamStore) {
                $streamStore->appendEvent($taskId, 'error', ['message' => $errMsg]);
            }

            $this->updateProgress($taskId, 'failed', '', null, 0, '', $errMsg);

            // 通知用户失败
            $ns = new NotificationService();
            $ns->notify($userId, 'agent_failed', 'AI 后台任务失败', mb_substr($errMsg, 0, 200), $sessionId);

            $this->logger->error("[AIBgService] Task {$taskId} failed: " . $errMsg);

            return ['success' => false, 'error' => $errMsg];
        }
    }

    /**
     * 查询任务进度。
     *
     * @param string $taskId 任务ID
     * @param int $userId 用户ID（防越权）
     * @return array|null 进度记录
     */
    public function getProgress(string $taskId, int $userId): ?array
    {
        return $this->db->fetch(
            "SELECT task_id, session_id, status, current_tool, iteration, progress_percent,
                    result_summary, error_message, created_at, updated_at
             FROM ai_agent_progress WHERE task_id = ? AND user_id = ?",
            [$taskId, $userId]
        );
    }

    /**
     * 获取用户的后台任务列表。
     *
     * @param int $userId 用户ID
     * @param int $limit 返回数量
     * @return array 任务列表
     */
    public function listTasks(int $userId, int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT task_id, session_id, status, current_tool, iteration, progress_percent,
                    result_summary, error_message, created_at, updated_at
             FROM ai_agent_progress WHERE user_id = ?
             ORDER BY created_at DESC LIMIT ?",
            [$userId, $limit]
        );
    }

    // ========================================================================
    //  内部辅助
    // ========================================================================

    /**
     * 更新进度记录。
     */
    private function updateProgress(
        string $taskId,
        string $status,
        string $currentTool = '',
        ?int $iteration = null,
        ?int $percent = null,
        string $resultSummary = '',
        string $errorMessage = ''
    ): void {
        $fields = [
            'status' => $status,
            'current_tool' => $currentTool,
            'result_summary' => $resultSummary,
            'error_message' => $errorMessage,
            'updated_at' => time(),
        ];
        if ($iteration !== null) {
            $fields['iteration'] = $iteration;
        }
        if ($percent !== null) {
            $fields['progress_percent'] = $percent;
        }

        $this->db->update('ai_agent_progress', $fields, 'task_id = ?', [$taskId]);
    }

    /**
     * 节流更新进度（每 3 秒最多写一次）。
     */
    private function throttledUpdateProgress(
        string $taskId,
        string $status,
        string $currentTool = '',
        ?int $iteration = null,
        ?int $percent = null
    ): void {
        $now = time();
        if ($now - $this->lastProgressWrite < $this->progressThrottle) {
            return;
        }
        $this->lastProgressWrite = $now;
        $this->updateProgress($taskId, $status, $currentTool, $iteration, $percent);
    }

    /**
     * 获取用户名。
     */
    private function getUsername(int $userId): string
    {
        $row = $this->db->fetch("SELECT username FROM users WHERE id = ?", [$userId]);
        return $row['username'] ?? 'User';
    }

    /**
     * 检查任务是否被取消。
     *
     * 前端 POST cancelTask 端点会将 cancel_requested 设为 1，
     * Worker 在 Agent 循环每轮迭代（回调触发）时检查此标志。
     *
     * @param string $taskId 任务ID
     * @return bool 是否已取消
     */
    private function isCancelled(string $taskId): bool
    {
        $row = $this->db->fetch(
            "SELECT cancel_requested FROM ai_agent_progress WHERE task_id = ?",
            [$taskId]
        );
        return is_array($row) && (int)($row['cancel_requested'] ?? 0) === 1;
    }
}
