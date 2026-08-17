<?php

namespace App\Core;

/**
 * Web 请求内 Worker 守护。
 *
 * 在 Web 请求末尾调用 checkAndSpawnIfNeeded()，按需拉起后台 Worker 进程。
 * 无需 cron/supervisor/systemd — 每个正常 Web 请求都是潜在的 Worker 拉起点。
 *
 * 触发策略：
 * - 有 pending ai_agent_task 时 100% 触发
 * - 距上次检查 > 30s 时 100% 触发
 * - 其余情况 10% 随机触发
 *
 * 降级策略：
 * - Worker 心跳正常 → 跳过
 * - Worker 死亡 → spawn（proc_open 启动 CLI 守护进程）
 * - spawn 失败 → 记录 error_log 告警，等待手动启动或下次自愈
 */
class WorkerGuard
{
    /** 最少检查间隔（秒） */
    private static int $checkInterval = 30;

    /** 随机触发概率 */
    private static float $randomCheckRate = 0.1;

    /**
     * 检查 Worker 存活状态，按需拉起。
     *
     * 应在 Web 请求末尾（页面输出完成后）调用，不阻塞用户请求。
     */
    public static function checkAndSpawnIfNeeded(): void
    {
        // 维护模式期间跳过 Worker 拉起，更新期间由 Updater 控制 Worker 生命周期
        if (file_exists(DATA_PATH . DIRECTORY_SEPARATOR . '.maintenance')) {
            return;
        }

        $lastCheckFile = DATA_PATH . DIRECTORY_SEPARATOR . '.worker_guard_lastcheck';
        $guardLockFile = DATA_PATH . DIRECTORY_SEPARATOR . '.worker_guard_lock';

        if (!self::shouldCheck($lastCheckFile)) {
            return;
        }

        // 文件锁防止并发检查（LOCK_NB 非阻塞：另一个请求已在检查时直接跳过）
        $fp = @fopen($guardLockFile, 'c+');
        if (!$fp) {
            return;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return;
        }

        try {
            $worker = new WorkerProcess();

            if ($worker->isAlive()) {
                return; // Worker 存活，无需操作
            }

            // Worker 死亡，尝试 spawn
            $spawned = $worker->spawn();
            if (!$spawned) {
                // spawn 失败，记录告警（不在 FPM 请求内执行任务，等待手动 php worker.php start 或下次自愈）
                error_log('[WorkerGuard] spawn failed, run "php worker.php start" manually');
            }
        } catch (\Throwable $e) {
            error_log('[WorkerGuard] Error: ' . $e->getMessage());
        } finally {
            // 记录本次检查时间
            @file_put_contents($lastCheckFile, (string)time());
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * 判断本次请求是否需要检查 Worker 心跳。
     */
    private static function shouldCheck(string $lastCheckFile): bool
    {
        // 1. 有 pending ai_agent_task → 100% 触发
        try {
            $db = Database::getInstance();
            $pending = (int)$db->fetch(
                "SELECT COUNT(*) AS cnt FROM async_tasks WHERE status = 'pending' AND type = 'ai_agent_task'"
            )['cnt'] ?? 0;
            if ($pending > 0) {
                return true;
            }
        } catch (\Throwable $e) {
            // 查询失败不阻塞主流程
        }

        // 2. 距上次检查 > 30s → 100% 触发
        $lastCheck = 0;
        if (file_exists($lastCheckFile)) {
            $lastCheck = (int)file_get_contents($lastCheckFile);
        }
        if (time() - $lastCheck > self::$checkInterval) {
            return true;
        }

        // 3. 随机 10% 触发
        return mt_rand(1, 100) <= (int)(self::$randomCheckRate * 100);
    }
}
