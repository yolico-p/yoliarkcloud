<?php

namespace App\Controllers\System;

use App\Controllers\BaseController;
use App\Core\Security;
use App\Core\Config;
use App\Core\Database;

class MonitorController extends BaseController
{
    public function systemInfo()
    {
        $this->requireAuth();

        $diskTotal = @disk_total_space(ROOT_PATH);
        $diskFree = @disk_free_space(ROOT_PATH);
        if ($diskTotal === false) $diskTotal = 0;
        if ($diskFree === false) $diskFree = 0;
        $diskUsed = $diskTotal - $diskFree;

        $db = Database::getInstance();
        $userCount = $db->fetch("SELECT COUNT(*) as count FROM users");
        $fileCount = $db->fetch("SELECT COUNT(*) as count FROM files WHERE is_dir = 0");
        $shareCount = $db->fetch("SELECT COUNT(*) as count FROM shares WHERE is_active = 1");
        $trashCount = $db->fetch("SELECT COUNT(*) as count FROM trash");

        Security::jsonOutput([
            'success' => true,
            'system' => [
                'php_version' => PHP_VERSION,
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'disk_total' => $diskTotal,
                'disk_free' => $diskFree,
                'disk_used' => $diskUsed,
                'disk_total_formatted' => Security::formatSize($diskTotal),
                'disk_free_formatted' => Security::formatSize($diskFree),
                'disk_used_formatted' => Security::formatSize($diskUsed),
                'disk_usage_percentage' => $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 1) : 0,
                'user_count' => $userCount['count'],
                'file_count' => $fileCount['count'],
                'share_count' => $shareCount['count'],
                'trash_count' => $trashCount['count'],
                'database_size' => file_exists(DB_PATH) ? Security::formatSize(filesize(DB_PATH)) : '0 B',
                'database_size_bytes' => file_exists(DB_PATH) ? filesize(DB_PATH) : 0,
            ],
        ]);
    }

    public function diskInfo()
    {
        $this->requireAuth();

        $diskTotal = @disk_total_space(ROOT_PATH);
        $diskFree = @disk_free_space(ROOT_PATH);
        if ($diskTotal === false) $diskTotal = 0;
        if ($diskFree === false) $diskFree = 0;
        $diskUsed = $diskTotal - $diskFree;

        $config = Config::getInstance();

        Security::jsonOutput([
            'success' => true,
            'disk' => [
                'total' => $diskTotal,
                'free' => $diskFree,
                'used' => $diskUsed,
                'total_formatted' => Security::formatSize($diskTotal),
                'free_formatted' => Security::formatSize($diskFree),
                'used_formatted' => Security::formatSize($diskUsed),
                'usage_percentage' => $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 1) : 0,
                'reserve_mb' => $config->get('storage_reserve_mb', 500),
                'update_threshold' => $config->get('storage_update_threshold', 1),
            ],
        ]);
    }

    public function getDiskInfo()
    {
        return $this->diskInfo();
    }

    public function storageSettings()
    {
        $this->requireAuth();

        $config = Config::getInstance();
        $db = Database::getInstance();

        $users = $db->fetchAll("SELECT id, username, storage_limit, storage_used FROM users");

        Security::jsonOutput([
            'success' => true,
            'users' => $users,
            'reserve_mb' => $config->get('storage_reserve_mb', 500),
            'update_threshold' => $config->get('storage_update_threshold', 1),
        ]);
    }

    public function updateStorage()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $config = Config::getInstance();
        $reserveMB = intval($this->input('reserve_mb', $this->input('storage_reserve_mb', $config->get('storage_reserve_mb', 500))));
        $threshold = intval($this->input('update_threshold', $this->input('storage_update_threshold', $config->get('storage_update_threshold', 1))));

        $config->set('storage_reserve_mb', $reserveMB);
        $config->set('storage_update_threshold', $threshold);
        $config->save();

        if ($this->input('reset_to_default')) {
            // 重置所有用户配额是高危操作，需二次确认（confirm=1）
            if (intval($this->input('confirm', 0)) !== 1) {
                Security::jsonOutput([
                    'success' => false,
                    'message' => '重置配额需二次确认，请传入 confirm=1',
                ], 400);
            }
            $db = Database::getInstance();
            $db->query('UPDATE users SET storage_limit = ?, updated_at = ?', [10737418240, time()]);
        } else {
            $this->updateStorageLimits($reserveMB);
        }

        $this->success('存储设置已更新');
    }

    public function updateStorageSettings()
    {
        return $this->updateStorage();
    }

    public function manualUpdateStorage()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $config = Config::getInstance();
        $reserveMB = $config->get('storage_reserve_mb', 500);

        $this->updateStorageLimits($reserveMB);

        $this->success('存储配额已手动更新');
    }

    private function updateStorageLimits($reserveMB)
    {
        $db = Database::getInstance();
        $diskFree = @disk_free_space(ROOT_PATH);
        $diskTotal = @disk_total_space(ROOT_PATH);

        if ($diskFree === false || $diskTotal === false) {
            $limit = 10737418240;
        } else {
            $reserveBytes = $reserveMB * 1024 * 1024;
            $available = max(0, $diskFree - $reserveBytes);
            $limit = min($available, min($diskTotal, 1099511627776));
            $limit = max(104857600, $limit);
        }

        $db->query('UPDATE users SET storage_limit = ?, updated_at = ?', [$limit, time()]);
    }
}
