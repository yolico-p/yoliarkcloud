<?php

namespace App\Models;

use App\Core\Model;

class Inbox extends Model
{
    protected static string $table = 'inbox_files';
    protected static array $fillable = [
        'user_id', 'filename', 'filepath', 'filesize', 'file_type', 'mime_type',
        'sender_name', 'sender_message', 'inbox_token', 'created_at',
    ];
    protected static array $guarded = ['id'];

    public static function listByUser(int $userId): array
    {
        return self::where('user_id = ?', [$userId])
            ->orderBy('created_at', 'DESC')
            ->get();
    }

    public static function countByUser(int $userId): int
    {
        return self::count('user_id = ?', [$userId]);
    }

    public static function totalSizeByUser(int $userId): int
    {
        $table = static::getTable();
        $row = static::db()->fetch("SELECT COALESCE(SUM(filesize), 0) AS total FROM {$table} WHERE user_id = ?", [$userId]);
        return (int) ($row['total'] ?? 0);
    }

    public static function findByToken(string $token): ?self
    {
        return self::where('inbox_token = ?', [$token])->first();
    }

    public static function findByIdAndUser(int $id, int $userId): ?self
    {
        return self::where('id = ? AND user_id = ?', [$id, $userId])->first();
    }
}
