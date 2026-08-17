<?php

namespace App\Models;

use App\Core\Model;
use App\Core\Security;
use App\Core\Config;

class File extends Model
{
    protected static string $table = 'files';
    protected static array $fillable = [
        'user_id', 'filename', 'filepath', 'filesize', 'file_type', 'mime_type',
        'is_dir', 'parent_id', 'path_hash', 'description',
        'is_favorite', 'is_locked', 'is_encrypted', 'sort_order',
        'tags', 'content_hash', 'created_at', 'updated_at',
    ];
    protected static array $guarded = ['id'];

    // ========================================================================
    //  便捷查询
    // ========================================================================

    public static function findByPathHash(int $userId, string $pathHash): ?self
    {
        return self::where('user_id = ? AND path_hash = ?', [$userId, $pathHash])->first();
    }

    public static function findByParent(int $userId, int $parentId, string $sortBy = 'filename', string $sortOrder = 'ASC', int $page = 1, int $pageSize = 100): array
    {
        $allowedSorts = ['filename', 'filesize', 'created_at', 'file_type', 'sort_order'];
        $column = in_array($sortBy, $allowedSorts) ? $sortBy : 'filename';
        $dir = strtoupper($sortOrder) === 'DESC' ? 'DESC' : 'ASC';
        $offset = ($page - 1) * $pageSize;

        $secondary = $column === 'sort_order' ? ', filename ASC' : '';
        $rows = static::db()->fetchCached(
            "SELECT * FROM files WHERE user_id = ? AND parent_id = ?
             ORDER BY is_dir DESC, {$column} {$dir}{$secondary}
             LIMIT ? OFFSET ?",
            [$userId, $parentId, $pageSize, $offset],
            ['files', 'user:' . $userId]
        );

        return array_map(fn ($r) => (new static())->forceFill($r), $rows);
    }

    public static function search(int $userId, string $keyword, int $limit = 50, int $offset = 0): array
    {
        $searchService = new \App\Core\SearchService(static::db(), static::db()->getQueryCache());
        $rows = $searchService->search($keyword, $userId, $limit, $offset);
        return array_map(fn ($r) => (new static())->forceFill($r), $rows);
    }

    public static function searchCount(int $userId, string $keyword): int
    {
        $searchService = new \App\Core\SearchService(static::db(), static::db()->getQueryCache());
        return $searchService->searchCount($keyword, $userId);
    }

    // ========================================================================
    //  格式化输出
    // ========================================================================

    public function toFormattedArray(): array
    {
        $attrs = $this->attributes;
        $attrs['filesize_formatted'] = Security::formatSize($attrs['filesize'] ?? 0);
        $attrs['created_at_formatted'] = Security::formatTime($attrs['created_at'] ?? 0);
        $attrs['updated_at_formatted'] = Security::formatTime($attrs['updated_at'] ?? 0);
        $attrs['tags'] = $this->parseTags($attrs['tags'] ?? '');
        return $attrs;
    }

    private function parseTags(string $tags): array
    {
        return $tags !== '' ? array_map('trim', explode(',', $tags)) : [];
    }
}
