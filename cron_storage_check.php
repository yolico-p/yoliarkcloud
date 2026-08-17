<?php

// 仅允许 CLI 执行，防止未授权 Web 访问
if (php_sapi_name() !== 'cli') {
    die('Access denied');
}

require_once __DIR__ . '/bootstrap/app.php';

use App\Core\Security;
use App\Core\Config;
use App\Core\Database;
use App\Services\TrashService;

define('CRON_LOG_FILE', DATA_PATH . DIRECTORY_SEPARATOR . 'cron_storage_check.log');

function cronLog($message)
{
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;
    file_put_contents(CRON_LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
    echo $logEntry;
}

function calculateStorageLimit($reserveBytes)
{
    $diskFree = @disk_free_space(ROOT_PATH);
    $diskTotal = @disk_total_space(ROOT_PATH);

    if ($diskFree === false || $diskTotal === false) {
        return 10737418240;
    }

    $available = max(0, $diskFree - $reserveBytes);
    $limit = min($available, min($diskTotal, 1099511627776));
    return max(104857600, $limit);
}

try {
    cronLog('开始执行存储空间检测任务');

    // 回收站清理
    $trashService = new TrashService();
    $trashCleanupCount = $trashService->cleanExpired();
    cronLog("回收站过期文件清理完成，清理了 {$trashCleanupCount} 条记录");

    $db = Database::getInstance();

    // 清理 90 天前的分享访问记录
    $cleanupVisits = $db->query('DELETE FROM share_visits WHERE created_at < ?', [time() - 90 * 86400]);
    cronLog("分享访问记录清理完成");

    $config = Config::getInstance();
    $reserveBytes = $config->get('storage_reserve_mb', 500) * 1024 * 1024;
    $threshold = $config->get('storage_update_threshold', 1);

    $users = $db->fetchAll("SELECT * FROM users WHERE role = 'admin' ORDER BY id ASC");

    if (empty($users)) {
        cronLog('警告: 未找到管理员用户');
        exit(1);
    }

    $updatedCount = 0;
    $skippedCount = 0;

    foreach ($users as $user) {
        $newLimit = calculateStorageLimit($reserveBytes);
        $currentLimit = $user['storage_limit'];
        $changePercent = $currentLimit > 0 ? abs($newLimit - $currentLimit) / $currentLimit * 100 : 100;

        if ($changePercent > $threshold) {
            $db->update('users', [
                'storage_limit' => $newLimit,
                'updated_at' => time(),
            ], 'id = ?', [$user['id']]);

            $oldFormatted = Security::formatSize($currentLimit);
            $newFormatted = Security::formatSize($newLimit);
            cronLog("用户 {$user['username']} 存储限制已更新: {$oldFormatted} -> {$newFormatted} (变化: " . round($changePercent, 2) . "%)");
            $updatedCount++;
        } else {
            cronLog("用户 {$user['username']} 存储限制无需更新 (当前: " . Security::formatSize($currentLimit) . ", 变化: " . round($changePercent, 2) . "%)");
            $skippedCount++;
        }
    }

    $freeSpace = @disk_free_space(ROOT_PATH);
    $totalSpace = @disk_total_space(ROOT_PATH);
    if ($freeSpace === false) $freeSpace = 0;
    if ($totalSpace === false) $totalSpace = 0;
    $usagePercent = $totalSpace > 0 ? round(($totalSpace - $freeSpace) / $totalSpace * 100, 2) : 0;

    cronLog("服务器磁盘状态 - 总空间: " . Security::formatSize($totalSpace) . ", 剩余: " . Security::formatSize($freeSpace) . ", 使用率: {$usagePercent}%");
    cronLog("存储空间检测任务完成 - 更新: {$updatedCount} 用户, 跳过: {$skippedCount} 用户");

} catch (\Exception $e) {
    cronLog('错误: ' . $e->getMessage());
    exit(1);
}
