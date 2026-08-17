<?php

namespace App\Models;

use App\Core\Model;

class OperationLog extends Model
{
    protected static string $table = 'operation_logs';
    protected static array $fillable = [
        'user_id', 'action', 'category', 'severity',
        'target', 'detail', 'ip', 'user_agent', 'created_at',
    ];
    protected static array $guarded = ['id'];

    public static function create(
        int $userId,
        string $action,
        string $detail = '',
        string $category = '',
        string $severity = 'info',
        string $target = ''
    ): self {
        $log = new self([
            'user_id'    => $userId,
            'action'     => $action,
            'category'   => $category ?: self::inferCategory($action),
            'severity'   => $severity ?: self::inferSeverity($action),
            'target'     => $target,
            'detail'     => $detail,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => time(),
        ]);
        $log->save();
        return $log;
    }

    public static function list(int $userId, int $page = 1, int $pageSize = 50, ?string $category = null, ?string $severity = null): array
    {
        $where = 'user_id = ?';
        $params = [$userId];

        if ($category) {
            $where .= ' AND category = ?';
            $params[] = $category;
        }
        if ($severity) {
            $where .= ' AND severity = ?';
            $params[] = $severity;
        }

        return self::where($where, $params)
            ->orderBy('created_at', 'DESC')
            ->limit($pageSize)
            ->offset(($page - 1) * $pageSize)
            ->get();
    }

    public static function listAll(int $page = 1, int $pageSize = 50, ?string $category = null, ?string $severity = null): array
    {
        $where = '1=1';
        $params = [];

        if ($category) {
            $where .= ' AND category = ?';
            $params[] = $category;
        }
        if ($severity) {
            $where .= ' AND severity = ?';
            $params[] = $severity;
        }

        return self::where($where, $params)
            ->orderBy('created_at', 'DESC')
            ->limit($pageSize)
            ->offset(($page - 1) * $pageSize)
            ->get();
    }

    private static function inferCategory(string $action): string
    {
        return match (true) {
            str_starts_with($action, 'login'), str_starts_with($action, 'logout'), $action === 'register', $action === 'change_password' => 'auth',
            str_starts_with($action, 'upload'), $action === 'download', $action === 'delete', $action === 'rename', $action === 'move', $action === 'copy', $action === 'create_folder' => 'file',
            str_starts_with($action, 'share') => 'share',
            $action === 'update_config', $action === 'clear_cache', $action === 'clear_logs' => 'system',
            default => 'other',
        };
    }

    private static function inferSeverity(string $action): string
    {
        return match ($action) {
            'permanent_delete', 'empty_trash', 'change_password' => 'critical',
            'delete', 'batch_delete', 'login', 'update_config', 'clear_logs' => 'warning',
            default => 'info',
        };
    }
}
