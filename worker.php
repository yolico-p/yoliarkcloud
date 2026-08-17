<?php

/**
 * Worker CLI 入口脚本。
 *
 * 命令：
 *   php worker.php              前台运行（等同 run）
 *   php worker.php run          前台运行主循环
 *   php worker.php start        启动后台守护进程
 *   php worker.php stop         停止守护进程
 *   php worker.php restart      重启守护进程
 *   php worker.php status       查询状态
 *
 * 自愈：WorkerGuard 在 Web 请求末尾按需通过 proc_open 拉起本脚本（run 命令）。
 */

require_once __DIR__ . '/bootstrap/app.php';

use App\Core\WorkerProcess;

$isCLI = (php_sapi_name() === 'cli');
if (!$isCLI) {
    http_response_code(404);
    exit('Not Found');
}

// CLI 模式下不需要 session
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

set_time_limit(0);
ini_set('memory_limit', '256M');

$command = $argv[1] ?? 'run';
$worker = new WorkerProcess();

switch ($command) {
    case 'run':
        // 前台运行主循环（默认）
        $worker->run();
        break;

    case 'start':
        // 启动后台守护进程
        if ($worker->isAlive()) {
            echo "Worker already running\n";
            exit(0);
        }
        $ok = $worker->spawn();
        if ($ok) {
            echo "Worker started\n";
            exit(0);
        }
        fwrite(STDERR, "Failed to start worker\n");
        exit(1);

    case 'stop':
        // 停止守护进程
        if (!$worker->isAlive()) {
            echo "Worker is not running\n";
            exit(0);
        }
        $worker->stop();
        // 轮询心跳消失（最长等待 30 秒）
        $elapsed = 0;
        while ($elapsed < 30) {
            if (!$worker->isAlive()) {
                echo "Worker stopped\n";
                exit(0);
            }
            sleep(1);
            $elapsed++;
        }
        fwrite(STDERR, "Worker stop timeout\n");
        exit(1);

    case 'restart':
        // 重启守护进程
        $result = $worker->restart();
        if ($result['ok']) {
            echo "Worker restarted, PID={$result['pid']}\n";
            exit(0);
        }
        fwrite(STDERR, "Worker restart failed\n");
        exit(1);

    case 'status':
        // 查询状态
        $status = $worker->getStatus();
        $alive = $status['alive'] ? 'running' : 'stopped';
        $pid = $status['pid'] ?? 0;
        $age = $status['heartbeat_age'] ?? -1;
        $queue = $status['queue'] ?? [];
        $cooldown = !empty($status['in_cooldown']) ? ' (in cooldown)' : '';
        echo "Worker: {$alive}{$cooldown}\n";
        echo "PID: {$pid}\n";
        echo "Heartbeat age: {$age}s\n";
        if (is_array($queue)) {
            echo "Queue: total={$queue['total']} pending={$queue['pending']} processing={$queue['processing']} failed={$queue['failed']}\n";
        }
        exit(0);

    default:
        // 未知命令输出 usage
        fwrite(STDERR, "Unknown command: {$command}\n");
        fwrite(STDERR, "Usage: php worker.php [run|start|stop|restart|status]\n");
        fwrite(STDERR, "  run      Run worker in foreground (default)\n");
        fwrite(STDERR, "  start    Start worker as background daemon\n");
        fwrite(STDERR, "  stop     Stop running worker\n");
        fwrite(STDERR, "  restart  Restart worker\n");
        fwrite(STDERR, "  status   Show worker status\n");
        exit(1);
}
