<?php

namespace App\Controllers\System;

use App\Controllers\BaseController;
use App\Core\Security;
use App\Services\AIAgentService;

class AIAgentController extends BaseController
{
    public function getConfig()
    {
        $this->requireAdmin();

        $config = $this->aiConfigService()->getAIConfig();

        Security::jsonOutput(['success' => true, 'config' => $config]);
    }

    public function saveConfig()
    {
        $this->requireAdmin();
        $this->validateCSRF();

        $apiKey = $this->input('api_key', '');
        $baseUrl = $this->input('base_url', '');
        $model = $this->input('model', '');
        $provider = $this->input('provider', 'custom');

        $result = $this->aiConfigService()->saveConfig($apiKey, $baseUrl, $model, $provider);

        Security::jsonOutput($result);
    }

    public function fetchModels()
    {
        $this->requireAdmin();

        $apiKey = $this->input('api_key', '');
        $baseUrl = $this->input('base_url', '');

        if (empty($baseUrl)) {
            $this->error('请提供 API 地址');
        }

        if (empty($apiKey) || strpos($apiKey, '*') !== false) {
            $configFile = DATA_PATH . DIRECTORY_SEPARATOR . 'ai_agent.json';
            if (file_exists($configFile)) {
                $existing = json_decode(file_get_contents($configFile), true) ?: [];
                if (strpos($apiKey, '*') !== false || empty($apiKey)) {
                    $apiKey = $existing['api_key'] ?? '';
                }
            }
        }

        $result = $this->aiConfigService()->fetchModels($apiKey, $baseUrl);
        Security::jsonOutput($result);
    }

    /**
     * 别名：列出模型（与 fetchModels 等价）
     */
    public function listModels()
    {
        return $this->fetchModels();
    }

    public function testConnection()
    {
        $this->requireAdmin();

        $apiKey = $this->input('api_key', '');
        $baseUrl = $this->input('base_url', '');

        if (empty($baseUrl)) {
            $this->error('请提供 API 地址');
        }

        if (empty($apiKey) || strpos($apiKey, '*') !== false) {
            $configFile = DATA_PATH . DIRECTORY_SEPARATOR . 'ai_agent.json';
            if (file_exists($configFile)) {
                $existing = json_decode(file_get_contents($configFile), true) ?: [];
                if (strpos($apiKey, '*') !== false || empty($apiKey)) {
                    $apiKey = $existing['api_key'] ?? '';
                }
            }
        }

        $result = $this->aiConfigService()->testConnection($apiKey, $baseUrl);
        Security::jsonOutput($result);
    }

    public function chat()
    {
        $this->requireAdmin();

        $messages = $this->input('messages', []);
        if (!is_array($messages)) {
            $messages = json_decode($messages, true) ?: [];
        }

        if (empty($messages)) {
            $content = $this->input('message', '');
            if (empty($content)) {
                $this->error('消息不能为空');
            }
            $messages = [['role' => 'user', 'content' => $content]];
        }

        $context = $this->input('context', null);

        $service = new AIAgentService();
        $result = $service->chat($messages, false, $context);

        Security::jsonOutput($result);
    }

    /**
     * @deprecated 已改用 chatStreamSubmit + chatStreamProgress，保留作降级路径。
     */
    public function chatStream()
    {
        if (empty($_SESSION['user_id'])) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            echo "data: " . json_encode(['type' => 'error', 'message' => '请先登录'], JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
            return;
        }

        $messages = $this->input('messages', []);
        if (!is_array($messages)) {
            $messages = json_decode($messages, true) ?: [];
        }

        $context = $this->input('context', null);
        $sessionId = $this->input('session_id', null);
        $confirmResume = $this->input('confirm_resume', false);

        // 确认恢复模式允许空 messages（从会话历史加载）；正常模式要求非空
        if (empty($messages) && !$confirmResume) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            echo "data: " . json_encode(['type' => 'error', 'message' => '消息不能为空'], JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
            return;
        }

        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', false);
        ignore_user_abort(true);
        set_time_limit(0);
        while (ob_get_level()) ob_end_clean();

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $service = new AIAgentService();

        // 如果有 session_id 且非确认恢复模式，保存用户消息
        if ($sessionId && !$confirmResume) {
            $this->aiSessionService()->saveMessagePublic($sessionId, 'user', end($messages)['content'] ?? '');
        }

        $service->chatStream($messages, function($type, $data) {
            echo "data: " . json_encode(array_merge(['type' => $type], $data), JSON_UNESCAPED_UNICODE) . "\n\n";
            if (ob_get_level()) ob_flush();
            flush();
        }, $context, $sessionId, $confirmResume);
    }

    /**
     * 提交流式对话任务到 CLI Worker（短请求，立即返回 task_id）。
     *
     * 替代旧的 chatStream（在 FPM 内跑长循环）。
     * 前端 POST 此端点 → 拿 task_id → EventSource 订阅 chatStreamProgress。
     *
     * @deprecated chatStream 保留作降级路径
     */
    public function chatStreamSubmit()
    {
        if (empty($_SESSION['user_id'])) {
            Security::jsonOutput(['success' => false, 'message' => '请先登录']);
            return;
        }
        $this->validateCSRF();

        $messages = $this->input('messages', []);
        if (!is_array($messages)) {
            $messages = json_decode($messages, true) ?: [];
        }

        $context = $this->input('context', null);
        $sessionId = $this->input('session_id', null);
        $confirmResume = $this->input('confirm_resume', false);

        // 确认恢复模式允许空 messages
        if (empty($messages) && !$confirmResume) {
            Security::jsonOutput(['success' => false, 'message' => '消息不能为空']);
            return;
        }

        // 若无 session 则创建
        if (empty($sessionId)) {
            $sessionResult = $this->aiSessionService()->createSession($context);
            $sessionId = $sessionResult['session_id'] ?? '';
        }

        // 非 confirm_resume 模式保存用户消息
        if ($sessionId && !$confirmResume && !empty($messages)) {
            $this->aiSessionService()->saveMessagePublic($sessionId, 'user', end($messages)['content'] ?? '');
        }

        $bgService = new \App\Services\AIAgentBackgroundService();
        $taskId = $bgService->enqueueTask($this->getUserId(), $sessionId, $messages, $context, true); // streamMode = true

        Security::jsonOutput(['success' => true, 'task_id' => $taskId, 'session_id' => $sessionId]);
    }

    /**
     * 流式进度订阅（SSE）。
     *
     * 轮询 StreamProgressStore 文件，转发 Worker 写入的事件给前端。
     * 每次读取 < 100ms，支持 last_seq 断线续传。
     */
    public function chatStreamProgress()
    {
        if (empty($_SESSION['user_id'])) {
            header('Content-Type: text/event-stream');
            echo "data: " . json_encode(['type' => 'error', 'message' => '请先登录'], JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
            return;
        }

        $taskId = $this->input('task_id', '');
        $lastSeq = (int)$this->input('last_seq', 0);

        if (empty($taskId)) {
            header('HTTP/1.1 400 Bad Request');
            exit('Missing task_id');
        }

        // 校验 task 归属当前用户
        $store = new \App\Core\StreamProgressStore();
        $status = $store->getTaskStatus($taskId);
        if ($status === 'unknown') {
            header('HTTP/1.1 404 Not Found');
            exit('Task not found');
        }

        // 进一步校验 user_id（查 ai_agent_progress 表）
        $db = \App\Core\Database::getInstance();
        $row = $db->fetch("SELECT user_id FROM ai_agent_progress WHERE task_id = ?", [$taskId]);
        if (!$row || (int)$row['user_id'] !== (int)$_SESSION['user_id']) {
            header('HTTP/1.1 403 Forbidden');
            exit('Forbidden');
        }

        // SSE 头
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', false);
        set_time_limit(0);
        ignore_user_abort(true);
        while (ob_get_level()) ob_end_clean();

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $maxIdleLoops = 7500; // 7500 * 200ms = 25 分钟上限
        $idleCount = 0;
        $statusCheckCounter = 0; // status 检查降频计数器
        $hasTerminalEvent = false; // 是否已发送终态事件

        while ($idleCount < $maxIdleLoops) {
            if (connection_aborted()) break;

            $events = $store->readEvents($taskId, $lastSeq);

            if (!empty($events)) {
                foreach ($events as $event) {
                    // 转发事件时附带 seq 字段，供前端追踪 lastSeq
                    $payload = array_merge(['type' => $event['type']], $event['data'] ?? []);
                    $payload['seq'] = (int)$event['seq'];
                    echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
                    $lastSeq = (int)$event['seq'];
                    $idleCount = 0; // 有事件，重置空闲计数

                    // 检测终态事件
                    if (in_array($event['type'], ['done', 'error'], true)) {
                        $hasTerminalEvent = true;
                    }
                }
                if (ob_get_level()) ob_flush();
                flush();

                // 已发送终态事件，关闭连接
                if ($hasTerminalEvent) {
                    return;
                }
                // 有事件时立即查一次 status
                $statusCheckCounter = 5;
            } else {
                // 无新事件，发心跳保活
                echo ": ping\n\n";
                if (ob_get_level()) ob_flush();
                flush();
                $idleCount++;
            }

            // status 检查降频：每 5 次 idle 循环（约 1 秒）查一次
            $statusCheckCounter++;
            if ($statusCheckCounter < 5) {
                usleep(200000); // 200ms
                continue;
            }
            $statusCheckCounter = 0;

            // 检查任务状态：completed/failed 时需确保前端收到终态事件
            $currentStatus = $store->getTaskStatus($taskId);
            if (in_array($currentStatus, ['completed', 'failed'], true)) {
                // 再读一次确保拿到终态事件
                $finalEvents = $store->readEvents($taskId, $lastSeq);
                $foundTerminal = false;
                foreach ($finalEvents as $event) {
                    $payload = array_merge(['type' => $event['type']], $event['data'] ?? []);
                    $payload['seq'] = (int)$event['seq'];
                    echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
                    $lastSeq = (int)$event['seq'];
                    if (in_array($event['type'], ['done', 'error'], true)) {
                        $foundTerminal = true;
                    }
                }

                // 如果文件中没有终态事件，合成一个发给前端
                // 场景：Worker 崩溃后 reclaimStaleTasks 更新了 status 但未写事件（非 stream 模式），
                // 或 DB 与文件之间的竞态窗口
                if (!$foundTerminal) {
                    $synthType = ($currentStatus === 'completed') ? 'done' : 'error';
                    $synthMsg = ($currentStatus === 'completed')
                        ? '任务已结束'
                        : '任务执行失败';
                    $synthPayload = [
                        'type' => $synthType,
                        'message' => $synthMsg,
                        'seq' => $lastSeq + 1,
                    ];
                    echo "data: " . json_encode($synthPayload, JSON_UNESCAPED_UNICODE) . "\n\n";
                }

                if (ob_get_level()) ob_flush();
                flush();
                return;
            }

            usleep(200000); // 200ms
        }

        // 超时关闭，前端会重连带 last_seq
    }

    // ── 会话管理 ──

    public function createSession()
    {
        $this->requireAdmin();
        $this->validateCSRF();

        $context = $this->input('context', null);
        $result = $this->aiSessionService()->createSession($context);

        Security::jsonOutput($result);
    }

    public function listSessions()
    {
        $this->requireAdmin();

        $page = intval($this->input('page', 1));
        $result = $this->aiSessionService()->listSessions($page);

        Security::jsonOutput($result);
    }

    public function getSessionMessages()
    {
        $this->requireAdmin();

        $sessionId = $this->input('session_id', '');
        if (empty($sessionId)) {
            $this->error('缺少会话ID');
        }

        $result = $this->aiSessionService()->getSessionMessages($sessionId);

        Security::jsonOutput($result);
    }

    public function deleteSession()
    {
        $this->requireAdmin();
        $this->validateCSRF();

        $sessionId = $this->input('session_id', '');
        if (empty($sessionId)) {
            $this->error('缺少会话ID');
        }

        $result = $this->aiSessionService()->deleteSession($sessionId);

        Security::jsonOutput($result);
    }

    public function updateSessionTitle()
    {
        $this->requireAdmin();
        $this->validateCSRF();

        $sessionId = $this->input('session_id', '');
        $title = $this->input('title', '');
        if (empty($sessionId)) {
            $this->error('缺少会话ID');
        }

        $result = $this->aiSessionService()->updateSessionTitle($sessionId, $title);

        Security::jsonOutput($result);
    }

    /**
     * 生成对话标题（异步调用）
     * 基于用户首条消息和 AI 首次回复生成简洁标题
     */
    public function generateTitle()
    {
        $this->requireAdmin();

        $firstUserMsg = $this->input('firstUserMsg', '');
        $firstAiMsg = $this->input('firstAiMsg', '');

        if (empty($firstUserMsg)) {
            Security::jsonOutput(['success' => false, 'error' => '缺少用户消息']);
            return;
        }

        $service = new AIAgentService();
        $result = $service->generateTitle($firstUserMsg, $firstAiMsg);

        Security::jsonOutput($result);
    }

    // ── 后台任务 & 通知 ──

    /**
     * 入队后台 AI Agent 任务。
     */
    public function chatBackground()
    {
        $this->requireAdmin();
        $this->validateCSRF();

        $messages = $this->input('messages', []);
        if (!is_array($messages)) {
            $messages = json_decode($messages, true) ?: [];
        }
        if (empty($messages)) {
            $this->error('消息不能为空');
        }

        $context = $this->input('context', null);
        $sessionId = $this->input('session_id', null);

        // 若无 session 则创建
        if (empty($sessionId)) {
            $sessionResult = $this->aiSessionService()->createSession($context);
            $sessionId = $sessionResult['session_id'] ?? '';
        }

        $bgService = new \App\Services\AIAgentBackgroundService();
        $taskId = $bgService->enqueueTask($this->getUserId(), $sessionId, $messages, $context);

        Security::jsonOutput(['success' => true, 'task_id' => $taskId, 'session_id' => $sessionId]);
    }

    /**
     * 查询后台任务进度。
     */
    public function getTaskStatus()
    {
        $this->requireAdmin();

        $taskId = $this->input('task_id', '');
        if (empty($taskId)) {
            $this->error('缺少任务ID');
        }

        $bgService = new \App\Services\AIAgentBackgroundService();
        $progress = $bgService->getProgress($taskId, $this->getUserId());

        if (!$progress) {
            Security::jsonOutput(['success' => false, 'message' => '任务不存在']);
            return;
        }

        Security::jsonOutput(['success' => true, 'progress' => $progress]);
    }

    /**
     * 获取通知列表。
     */
    public function getNotifications()
    {
        $this->requireAdmin();

        $ns = new \App\Core\NotificationService();
        $page = intval($this->input('page', 1));
        $result = $ns->getAll($this->getUserId(), $page);

        Security::jsonOutput(array_merge(['success' => true], $result));
    }

    /**
     * 获取未读通知数量。
     */
    public function getUnreadNotificationCount()
    {
        $this->requireAdmin();

        $ns = new \App\Core\NotificationService();
        $count = $ns->getUnreadCount($this->getUserId());

        Security::jsonOutput(['success' => true, 'count' => $count]);
    }

    /**
     * 标记单条通知已读。
     */
    public function markNotificationRead()
    {
        $this->requireAdmin();
        $this->validateCSRF();

        $id = intval($this->input('id', 0));
        if ($id <= 0) {
            $this->error('无效的通知ID');
        }

        $ns = new \App\Core\NotificationService();
        $ns->markRead($id, $this->getUserId());

        Security::jsonOutput(['success' => true]);
    }

    /**
     * 标记所有通知已读。
     */
    public function markAllNotificationsRead()
    {
        $this->requireAdmin();
        $this->validateCSRF();

        $ns = new \App\Core\NotificationService();
        $count = $ns->markAllRead($this->getUserId());

        Security::jsonOutput(['success' => true, 'count' => $count]);
    }

    /**
     * 获取 Worker 状态。
     */
    public function getWorkerStatus()
    {
        $this->requireAdmin();

        $wp = new \App\Core\WorkerProcess();
        Security::jsonOutput(['success' => true, 'worker' => $wp->getStatus()]);
    }

    /**
     * 取消正在执行的 AI Agent 任务。
     * 设置 cancel_requested=1，Worker 在 Agent 循环中检测后终止。
     */
    public function cancelTask()
    {
        $this->requireAdmin();
        $this->validateCSRF();

        $taskId = $this->input('task_id', '');
        if (empty($taskId)) {
            $this->error('缺少任务ID');
        }

        $db = \App\Core\Database::getInstance();
        $row = $db->fetch("SELECT user_id, status FROM ai_agent_progress WHERE task_id = ?", [$taskId]);
        if (!$row || (int)$row['user_id'] !== (int)$_SESSION['user_id']) {
            Security::jsonOutput(['success' => false, 'message' => '任务不存在或无权操作']);
            return;
        }

        if (in_array($row['status'], ['completed', 'failed'], true)) {
            Security::jsonOutput(['success' => false, 'message' => '任务已结束，无需取消']);
            return;
        }

        $db->update('ai_agent_progress', ['cancel_requested' => 1, 'updated_at' => time()], 'task_id = ?', [$taskId]);

        Security::jsonOutput(['success' => true]);
    }

    /**
     * 将流式任务转为后台执行。
     * 前端关闭 SSE 订阅，切换到粗粒度轮询。Worker 继续执行不受影响。
     */
    public function convertToBackground()
    {
        $this->requireAdmin();
        $this->validateCSRF();

        $taskId = $this->input('task_id', '');
        if (empty($taskId)) {
            $this->error('缺少任务ID');
        }

        $db = \App\Core\Database::getInstance();
        $row = $db->fetch("SELECT user_id, status FROM ai_agent_progress WHERE task_id = ?", [$taskId]);
        if (!$row || (int)$row['user_id'] !== (int)$_SESSION['user_id']) {
            Security::jsonOutput(['success' => false, 'message' => '任务不存在或无权操作']);
            return;
        }

        if (in_array($row['status'], ['completed', 'failed'], true)) {
            Security::jsonOutput(['success' => false, 'message' => '任务已结束']);
            return;
        }

        // 仅返回 task_id 让前端切换到轮询，Worker 继续执行
        Security::jsonOutput(['success' => true, 'task_id' => $taskId]);
    }
}

