<?php

namespace App\Models;

use App\Core\Model;

class Share extends Model
{
    protected static string $table = 'shares';
    protected static array $fillable = [
        'user_id', 'file_id', 'share_token', 'share_password',
        'download_count', 'max_downloads', 'expire_at',
        'created_at', 'is_active',
    ];
    protected static array $guarded = ['id'];

    // ========================================================================
    //  便捷查询
    // ========================================================================

    public static function findByToken(string $token): ?self
    {
        return self::where('share_token = ?', [$token])->first();
    }

    public static function listByUser(int $userId, int $page = 1, int $pageSize = 20): array
    {
        return self::where('user_id = ?', [$userId])
            ->orderBy('created_at', 'DESC')
            ->limit($pageSize)
            ->offset(($page - 1) * $pageSize)
            ->get();
    }

    public static function countByUser(int $userId): int
    {
        return self::count('user_id = ?', [$userId]);
    }

    // ========================================================================
    //  业务方法
    // ========================================================================

    public function isExpired(): bool
    {
        return $this->attributes['expire_at'] > 0 && time() > $this->attributes['expire_at'];
    }

    public function isDownloadLimitReached(): bool
    {
        return $this->attributes['max_downloads'] > 0 &&
            $this->attributes['download_count'] >= $this->attributes['max_downloads'];
    }

    public function hasPassword(): bool
    {
        return !empty($this->attributes['share_password']);
    }
}
