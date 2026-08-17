<?php

namespace App\Models;

use App\Core\Model;

class TrashItem extends Model
{
    protected static string $table = 'trash';
    protected static array $fillable = [
        'user_id', 'file_id', 'filename', 'filepath', 'filesize',
        'file_type', 'mime_type', 'is_dir', 'parent_id',
        'original_path', 'deleted_at', 'expire_at',
    ];
    protected static array $guarded = ['id'];

    public static function listByUser(int $userId, int $page = 1, int $pageSize = 50): array
    {
        return self::where('user_id = ?', [$userId])
            ->orderBy('deleted_at', 'DESC')
            ->limit($pageSize)
            ->offset(($page - 1) * $pageSize)
            ->get();
    }

    public static function countByUser(int $userId): int
    {
        return self::count('user_id = ?', [$userId]);
    }

    public static function findExpired(int $limit = 100): array
    {
        return self::where('expire_at > 0 AND expire_at < ?', [time()])
            ->limit($limit)
            ->get();
    }

    public static function deleteByUserAndFile(int $userId, int $fileId): void
    {
        static::db()->delete(static::$table, 'user_id = ? AND file_id = ?', [$userId, $fileId]);
        static::db()->invalidateTableCache(static::$table);
    }
}
