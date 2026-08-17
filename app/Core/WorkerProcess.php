<?php

namespace App\Core;

use App\Core\Database;
use App\Core\AsyncTaskQueue;
use App\Core\AsyncLogger;

/**
 * 后台工作进程管理。
 *
 * 核心能力：
 * - spawn(): 通过 proc_open 启动独立后台 CLI 进程
 * - run(): Worker 主循环 — 心跳、消费队列、定时任务
 * - isAlive(): 检查心跳文件判断存活
 *
 * 零部署：无需 cron/supervisor/systemd，由 WorkerGuard 在 Web 请求中按需拉起。
 */
class WorkerProcess
{
    private string $heartbeatFile;
    private string $pidFile;
    private string $stopFile;
    private string $stateFile;
    private string $restartCountFile;
    private string $cooldownFile;

    /** 心跳超时阈值（秒） */
    private int $heartbeatTimeout = 60;
    /** 内存上限（字节） */
    private int $memoryLimit = 134217728; // 128MB
    /** 主循环 sleep 间隔（秒） */
    private int $loopInterval = 2;
    /** 崩溃冷却阈值：5 分钟内重启超过此数则冷却 */
    private int $crashThreshold = 3;
    /** 冷却期（秒） */
    private int $cooldownDuration = 600;

    public function __construct()
    {
        $this->heartbeatFile = DATA_PATH . DIRECTORY_SEPARATOR . '.worker_heartbeat';
        $this->pidFile = DATA_PATH . DIRECTORY_SEPARATOR . '.worker_pid';
        $this->stopFile = DATA_PATH . DIRECTORY_SEPARATOR . '.worker_stop';
        $this->stateFile = DATA_PATH . DIRECTORY_SEPARATOR . '.scheduled_state.json';
        $this->restartCountFile = DATA_PATH . DIRECTORY_SEPARATOR . '.worker_restart_count';
        $this->cooldownFile = DATA_PATH . DIRECTORY_SEPARATOR . '.worker_cooldown';
    }

    // ========================================================================
    //  启动 Worker
    // ========================================================================

    /**
     * 尝试通过 proc_open 启动后台 Worker 进程。
     *
     * 启动策略：
     * 1. proc_open + "php worker.php run"（纯 CLI 后台进程）
     * 2. proc_open 失败时记录告警并返回 false
     *
     * @return bool 是否成功启动
     */
    public function spawn(): bool
    {
        // 冷却期内不 spawn
        if ($this->isInCooldown()) {
            return false;
        }

        $spawned = $this->spawnViaProcOpen();
        if ($spawned) {
            $this->recordRestart();
            return true;
        }

        // proc_open 失败，不再降级到 FPM
        return false;
    }

    /**
     * 通过 proc_open 启动后台 Worker 进程。
     */
    private function spawnViaProcOpen(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }

        $phpBinary = PHP_BINARY ?: 'php';
        $workerScript = ROOT_PATH . DIRECTORY_SEPARATOR . 'worker.php';

        if (!file_exists($workerScript)) {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows: start /B 创建 detached 进程
            $cmd = "start /B \"YoliWorker\" \"{$phpBinary}\" \"{$workerScript}\" run";
            $descriptors = [
                ['pipe', 'r'],
                ['file', DATA_PATH . DIRECTORY_SEPARATOR . 'worker.log', 'a'],
                ['file', DATA_PATH . DIRECTORY_SEPARATOR . 'worker_error.log', 'a'],
            ];
        } else {
            // Linux/Unix: exec 替换 shell 进程，输出重定向
            $cmd = "exec {$phpBinary} {$workerScript} run";
            $descriptors = [
                ['file', '/dev/null', 'r'],
                ['file', DATA_PATH . DIRECTORY_SEPARATOR . 'worker.log', 'a'],
                ['file', DATA_PATH . DIRECTORY_SEPARATOR . 'worker_error.log', 'a'],
            ];
        }

        $process = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return false;
        }

        // 关闭 stdin 管道（Windows），让进程独立
        if (is_resource($pipes[0] ?? null)) {
            fclose($pipes[0]);
        }

        // 给进程一点启动时间，然后检查状态
        usleep(200000); // 200ms

        $status = proc_get_status($process);
        $pid = $status['pid'] ?? 0;

        proc_close($process);

        if ($pid > 0) {
            // 写入 PID 文件
            $this->atomicWrite($this->pidFile, (string)$pid);
            return true;
        }

        return false;
    }

    // ========================================================================
    //  存活检查
    // ========================================================================

    /**
     * 检查 Worker 是否存活（心跳未超时 + PID 进程存在）。
     */
    public function isAlive(): bool
    {
        if (!file_exists($this->heartbeatFile)) {
            return false;
        }

        $content = @file_get_contents($this->heartbeatFile);
        if ($content === false) {
            return false;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['timestamp'])) {
            return false;
        }

        // 心跳超时检查
        if (time() - (int)$data['timestamp'] > $this->heartbeatTimeout) {
            return false;
        }

        // PID 进程存在检查（Linux 用 posix_kill，Windows 用 tasklist）
        $pid = (int)($data['pid'] ?? 0);
        if ($pid > 0 && !$this->isProcessRunning($pid)) {
            return false;
        }

        return true;
    }

    /**
     * 检查进程是否在运行。
     */
    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows: tasklist 检查
            $output = @shell_exec("tasklist /FI \"PID eq {$pid}\" /NH 2>nul");
            return $output !== null && strpos($output, (string)$pid) !== false;
        } else {
            // Linux: posix_kill(pid, 0) 不发送信号只检查存在
            if (function_exists('posix_kill')) {
                return @posix_kill($pid, 0);
            }
            // 回退：检查 /proc
            return file_exists("/proc/{$pid}");
        }
    }

    // ========================================================================
    //  Worker 主循环
    // ========================================================================

    /**
     * Worker 主循环入口（由 worker.php 调用）。
     *
     * 循环逻辑：
     * 1. 写入心跳时间戳
     * 2. 检查内存与停止信号，超限则优雅退出
     * 3. 消费 async_tasks 队列
     * 4. 检查定时任务是否到期
     * 5. sleep 避免空转
     */
    public function run(): void
    {
        $logger = AsyncLogger::getInstance();

        $logger->info('[Worker] Starting, PID=' . getmypid());

        // 清理旧的停止标志
        @unlink($this->stopFile);

        // 清理 24 小时前的 stream 进度文件
        try {
            $cleaned = (new \App\Core\StreamProgressStore())->cleanupOldFiles(86400);
            if ($cleaned > 0) {
                $logger->info("[Worker] Cleaned {$cleaned} old stream files");
            }
        } catch (\Throwable $e) {
            $logger->error('[Worker] Stream file cleanup failed: ' . $e->getMessage());
        }

        while (true) {
            // 1. 检查退出条件
            if (memory_get_usage(true) > $this->memoryLimit) {
                $logger->info('[Worker] Memory limit reached, exiting');
                break;
            }
            if ($this->shouldStop()) {
                $logger->info('[Worker] Stop signal received, exiting');
                break;
            }

            // 2. 写心跳
            $this->writeHeartbeat();

            // 3. 消费任务队列
            try {
                $queue = AsyncTaskQueue::getInstance();
                $tasks = $queue->dequeue(5);

                if (!empty($tasks)) {
                    foreach ($tasks as $task) {
                        try {
                            $result = $this->executeTask($task);
                            $queue->complete($task['id'], $result);
                            $logger->info("[Worker] Task {$task['id']} completed", ['type' => $task['type']]);
                        } catch (\Throwable $e) {
                            $queue->fail($task['id'], $e->getMessage());
                            $logger->error("[Worker] Task {$task['id']} failed: " . $e->getMessage());
                        }
                    }
                    continue; // 有任务时不 sleep，立即检查下一个
                }
            } catch (\Throwable $e) {
                $logger->error('[Worker] Queue error: ' . $e->getMessage());
            }

            // 4. 定时任务
            try {
                $this->runScheduledTasks();
            } catch (\Throwable $e) {
                $logger->error('[Worker] Scheduled task error: ' . $e->getMessage());
            }

            // 5. 空转等待
            sleep($this->loopInterval);
        }

        // 优雅退出清理
        $this->cleanup();
        $logger->info('[Worker] Exited cleanly');
    }

    /**
     * 执行单个任务（委派至 AsyncTaskQueue::executeTask 或专用处理器）。
     */
    private function executeTask(array $task)
    {
        if ($task['type'] === 'ai_agent_task') {
            $bgService = new \App\Services\AIAgentBackgroundService();
            return $bgService->executeTask($task);
        }

        // 其他类型委派给 AsyncTaskQueue
        $queue = AsyncTaskQueue::getInstance();
        // 使用反射调用 private executeTask — 或者直接在 AsyncTaskQueue 中添加公开方法
        // 为简化实现，将 ai_agent_task 之外的任务通过队列自身处理
        return $queue->processTaskFromWorker($task);
    }

    /**
     * 检查并执行到期的定时任务。
     */
    private function runScheduledTasks(): void
    {
        $state = [];
        if (file_exists($this->stateFile)) {
            $state = json_decode(file_get_contents($this->stateFile), true) ?: [];
        }

        $now = time();
        $tasks = [
            'trash_cleanup' => ['interval' => 3600, 'handler' => function () {
                $ts = new \App\Services\TrashService();
                return $ts->cleanExpired();
            }],
            'storage_check' => ['interval' => 86400, 'handler' => function () {
                // 复用 cron_storage_check.php 的逻辑简化版
                $db = Database::getInstance();
                $users = $db->fetchAll("SELECT id, storage_limit FROM users WHERE role = 'admin'");
                $diskFree = @disk_free_space(ROOT_PATH);
                if ($diskFree === false) return 0;
                $reserve = 500 * 1024 * 1024;
                $available = max(0, $diskFree - $reserve);
                $diskTotal = @disk_total_space(ROOT_PATH) ?: PHP_INT_MAX;
                $newLimit = max(104857600, min($available, min($diskTotal, 1099511627776)));
                $updated = 0;
                foreach ($users as $user) {
                    $current = (int)$user['storage_limit'];
                    $change = $current > 0 ? abs($newLimit - $current) / $current * 100 : 100;
                    if ($change > 1) {
                        $db->update('users', ['storage_limit' => $newLimit, 'updated_at' => time()], 'id = ?', [$user['id']]);
                        $updated++;
                    }
                }
                return $updated;
            }],
            'cache_cleanup' => ['interval' => 86400, 'handler' => function () {
                $cacheDir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cache';
                if (!is_dir($cacheDir)) return 0;
                $count = 0;
                foreach (glob($cacheDir . DIRECTORY_SEPARATOR . '*') as $file) {
                    if (is_file($file) && filemtime($file) < time() - 86400 * 7) {
                        @unlink($file);
                        $count++;
                    }
                }
                return $count;
            }],
            'notification_cleanup' => ['interval' => 86400, 'handler' => function () {
                $ns = new NotificationService();
                return $ns->cleanupOld();
            }],
        ];

        foreach ($tasks as $name => $config) {
            $lastRun = $state[$name] ?? 0;
            if ($now - $lastRun >= $config['interval']) {
                try {
                    $result = call_user_func($config['handler']);
                    $state[$name] = $now;
                } catch (\Throwable $e) {
                    error_log("[Worker] Scheduled task {$name} failed: " . $e->getMessage());
                }
            }
        }

        file_put_contents($this->stateFile, json_encode($state), LOCK_EX);
    }

    // ========================================================================
    //  心跳
    // ========================================================================

    /**
     * 写入心跳（原子写入 tmp + rename）。
     */
    private function writeHeartbeat(): void
    {
        $data = json_encode([
            'pid' => getmypid(),
            'timestamp' => time(),
        ]);
        $this->atomicWrite($this->heartbeatFile, $data);
    }

    // ========================================================================
    //  停止
    // ========================================================================

    /**
     * 请求 Worker 停止（写入停止标志文件）。
     */
    public function stop(): void
    {
        @file_put_contents($this->stopFile, (string)time());

        // Linux 下可选发送 SIGTERM
        if (DIRECTORY_SEPARATOR !== '\\' && file_exists($this->pidFile)) {
            $pid = (int)file_get_contents($this->pidFile);
            if ($pid > 0 && function_exists('posix_kill')) {
                @posix_kill($pid, SIGTERM);
            }
        }
    }

    /**
     * 检查是否收到停止信号。
     */
    private function shouldStop(): bool
    {
        return file_exists($this->stopFile);
    }

    /**
     * 热更新场景下的优雅重启。
     *
     * 流程：
     * 1. 调用 stop() 写停止标志（Linux 下发 SIGTERM）
     * 2. 轮询心跳文件消失（每 1 秒一次，最长等待 30 秒）
     *    —— 心跳仍存在说明 Worker 正在处理长任务，继续等待直到超时
     * 3. 心跳消失后调用 spawn() 拉起新 Worker
     * 4. 短暂等待新心跳写入，读取新进程 PID
     *
     * @return array{ok: bool, pid: int}
     *   - ok=true  表示新 Worker 存活（spawn 返回 true），pid 为新进程 PID
     *   - ok=false 表示超时或 spawn 失败，pid 为 0
     */
    public function restart(): array
    {
        // 1. 请求当前 Worker 停止（写停止标志 + Linux 下 SIGTERM）
        $this->stop();

        // 2. 轮询心跳文件消失，最长等待 30 秒
        $stopTimeout = 30;
        $elapsed = 0;
        while ($elapsed < $stopTimeout) {
            if (!file_exists($this->heartbeatFile)) {
                break;
            }
            sleep(1);
            $elapsed++;
        }

        // 超时后心跳仍存在 → Worker 可能在处理长任务，放弃重启
        if (file_exists($this->heartbeatFile)) {
            return ['ok' => false, 'pid' => 0];
        }

        // 3. 拉起新 Worker
        if (!$this->spawn()) {
            return ['ok' => false, 'pid' => 0];
        }

        // 4. 短暂等待新 Worker 写入心跳，读取新进程 PID
        $pid = 0;
        $waitTimeout = 5;
        $waited = 0;
        while ($waited < $waitTimeout) {
            if (file_exists($this->heartbeatFile)) {
                $data = json_decode(@file_get_contents($this->heartbeatFile), true);
                if (is_array($data) && !empty($data['pid'])) {
                    $pid = (int)$data['pid'];
                    break;
                }
            }
            sleep(1);
            $waited++;
        }

        return ['ok' => true, 'pid' => $pid];
    }

    // ========================================================================
    //  崩溃恢复与冷却
    // ========================================================================

    /**
     * 记录重启次数，超过阈值则进入冷却。
     */
    private function recordRestart(): void
    {
        $now = time();
        $records = [];
        if (file_exists($this->restartCountFile)) {
            $records = json_decode(file_get_contents($this->restartCountFile), true) ?: [];
        }

        // 只保留最近 5 分钟的记录
        $records = array_filter($records, fn($t) => $now - $t < 300);
        $records[] = $now;

        file_put_contents($this->restartCountFile, json_encode($records), LOCK_EX);

        // 超过阈值，进入冷却
        if (count($records) >= $this->crashThreshold) {
            file_put_contents($this->cooldownFile, (string)($now + $this->cooldownDuration), LOCK_EX);
        }
    }

    /**
     * 是否在冷却期内。
     */
    private function isInCooldown(): bool
    {
        if (!file_exists($this->cooldownFile)) {
            return false;
        }
        $until = (int)file_get_contents($this->cooldownFile);
        if (time() >= $until) {
            @unlink($this->cooldownFile);
            return false;
        }
        return true;
    }

    // ========================================================================
    //  清理
    // ========================================================================

    /**
     * 退出时清理临时文件。
     */
    private function cleanup(): void
    {
        @unlink($this->heartbeatFile);
        @unlink($this->pidFile);
        @unlink($this->stopFile);
    }

    /**
     * 获取 Worker 状态信息。
     */
    public function getStatus(): array
    {
        $alive = $this->isAlive();
        $pid = 0;
        $lastHeartbeat = 0;

        if (file_exists($this->heartbeatFile)) {
            $data = json_decode(file_get_contents($this->heartbeatFile), true);
            if (is_array($data)) {
                $pid = (int)($data['pid'] ?? 0);
                $lastHeartbeat = (int)($data['timestamp'] ?? 0);
            }
        }

        $queue = AsyncTaskQueue::getInstance();
        $queueStats = $queue->getQueueStats();

        return [
            'alive' => $alive,
            'pid' => $pid,
            'last_heartbeat' => $lastHeartbeat,
            'heartbeat_age' => $lastHeartbeat > 0 ? time() - $lastHeartbeat : -1,
            'in_cooldown' => $this->isInCooldown(),
            'queue' => $queueStats,
        ];
    }

    // ========================================================================
    //  原子写入辅助
    // ========================================================================

    /**
     * 原子写入：先写临时文件再 rename，防止读到半写状态。
     */
    private function atomicWrite(string $path, string $content): void
    {
        $tmp = $path . '.tmp';
        @file_put_contents($tmp, $content, LOCK_EX);
        @rename($tmp, $path);
    }
}
