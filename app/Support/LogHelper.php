<?php

namespace App\Support;

/**
 * 操作日志辅助方法。
 *
 * 各 Service 通过 `use LogHelper;` 引入，避免重复定义
 * logOperation / getLogCategory / getLogSeverity 三个方法。
 *
 * 要求宿主类提供：
 *   - $this->db   (App\Core\Database)
 *   - $this->auth (App\Services\AuthService)  或自行实现 getLogUserId()
 */
trait LogHelper
{
    /**
     * 记录操作日志。
     * 跳过频繁的下载/分片上传动作，避免日志爆炸。
     */
    private function logOperation($action, $target = '')
    {
        // 跳过频繁操作：下载和分片上传不需要审计记录
        $skipActions = ['download', 'download_folder', 'upload_chunk'];
        if (in_array($action, $skipActions, true)) {
            return;
        }

        $userId = $this->getLogUserId();
        if (!$userId) {
            return;
        }

        $this->db->insert('operation_logs', [
            'user_id'    => $userId,
            'action'     => $action,
            'category'   => $this->getLogCategory($action),
            'severity'   => $this->getLogSeverity($action),
            'target'     => $target,
            'ip'         => \App\Core\Security::getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => time(),
        ]);
    }

    /**
     * 获取当前用户 ID。
     * 默认从 $this->auth 获取，AuthService 自身可重写此方法。
     */
    private function getLogUserId()
    {
        if (isset($this->auth) && $this->auth !== null) {
            return $this->auth->getUserId();
        }
        if (method_exists($this, 'getUserId')) {
            return $this->getUserId();
        }
        return null;
    }

    private function getLogCategory($action)
    {
        $categories = [
            'login' => 'auth', 'logout' => 'auth', 'change_password' => 'auth', 'register' => 'auth',
            'upload' => 'file', 'upload_chunk' => 'file', 'download' => 'file', 'download_folder' => 'file',
            'delete' => 'file', 'batch_delete' => 'file', 'rename' => 'file', 'move' => 'file',
            'copy' => 'file', 'create_folder' => 'file', 'restore' => 'file', 'permanent_delete' => 'file',
            'empty_trash' => 'file', 'update_description' => 'file', 'update_tags' => 'file',
            'toggle_favorite' => 'file', 'toggle_lock' => 'file', 'toggle_encryption' => 'file',
            'create_share' => 'share', 'delete_share' => 'share', 'toggle_share' => 'share',
            'update_profile' => 'account', 'update_config' => 'system', 'clear_cache' => 'system',
            'clear_logs' => 'system',
        ];
        return $categories[$action] ?? 'other';
    }

    private function getLogSeverity($action)
    {
        $critical = ['permanent_delete', 'empty_trash', 'change_password'];
        $warning  = ['delete', 'batch_delete', 'login', 'update_config', 'clear_logs'];
        if (in_array($action, $critical, true)) return 'critical';
        if (in_array($action, $warning, true))  return 'warning';
        return 'info';
    }
}
