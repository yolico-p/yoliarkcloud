<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Support\FileTypeTrait;

class FileFavoriteService
{
    use FileTypeTrait;

    private $db;
    private $auth;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->auth = new AuthService();
    }

    public function toggleFavorite($fileId)
    {
        $userId = $this->auth->getUserId();
        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);

        if (!$file) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        $newStatus = $file['is_favorite'] ? 0 : 1;
        $this->db->update('files', ['is_favorite' => $newStatus, 'updated_at' => time()], 'id = ? AND user_id = ?', [$fileId, $userId]);

        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => $newStatus ? '已添加收藏' : '已取消收藏', 'is_favorite' => $newStatus];
    }

    public function getFavorites($page = 1, $pageSize = 50)
    {
        $userId = $this->auth->getUserId();
        $offset = ($page - 1) * $pageSize;

        $files = $this->db->fetchCached(
            "SELECT * FROM files WHERE user_id = ? AND is_favorite = 1
             ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$userId, $pageSize, $offset],
            ['files', 'user:' . $userId]
        );

        foreach ($files as &$file) {
            $file['filesize_formatted'] = Security::formatSize($file['filesize']);
            $file['created_at_formatted'] = Security::formatTime($file['created_at']);
            $file['icon'] = $this->getFileIcon($file);
            $file['tags'] = $this->parseTags($file['tags'] ?? '');
            $file['thumbnail_url'] = $this->buildThumbnailUrl($file);
        }

        return $files;
    }

    public function getFavoritesCount()
    {
        $userId = $this->auth->getUserId();
        $result = $this->db->fetch(
            "SELECT COUNT(*) as count FROM files WHERE user_id = ? AND is_favorite = 1",
            [$userId]
        );
        return $result['count'];
    }

    private function buildThumbnailUrl($file)
    {
        if (!empty($file['is_dir']) || !$this->hasThumbnailSupport($file['file_type'] ?? '')) {
            return null;
        }

        $contentHash = $file['content_hash'] ?? '';
        $updatedAt = $file['updated_at'] ?? '';
        $cacheKey = intval($file['id'] ?? $file['file_id'] ?? 0) . '_' . (!empty($contentHash)
            ? $contentHash
            : md5(($file['filepath'] ?? '') . '_' . $updatedAt));
        $ext = strtolower($file['file_type'] ?? '');

        $audioExts = ['mp3', 'wav', 'flac', 'aac', 'ogg', 'wma', 'm4a', 'aiff', 'aif', 'opus', 'ape', 'alac', 'ra', 'ram', 'ac3', 'amr', 'mid', 'midi'];
        if (in_array($ext, $audioExts, true)) {
            $coverSvc = new \App\Services\AudioCoverService();
            $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . ($file['filepath'] ?? '');
            if (!$coverSvc->hasCover($fullPath, $ext, $cacheKey)) {
                return null;
            }
        } else {
            $thumbSvc = new \App\Services\ThumbnailService();
            if ($thumbSvc->isSourceTooLarge($file['filesize'] ?? 0) || $thumbSvc->isGenerationFailed($cacheKey)) {
                return null;
            }
        }

        $hashParam = !empty($contentHash)
            ? substr($contentHash, 0, 16)
            : substr(md5(($file['filepath'] ?? '') . '_' . $updatedAt), 0, 16);
        $fileId = intval($file['id'] ?? $file['file_id'] ?? 0);
        return 'index.php?action=thumbnail&file_id=' . $fileId . '&h=' . $hashParam;
    }

    private function parseTags($tagsStr)
    {
        if (empty($tagsStr)) return [];
        return array_values(array_filter(array_map('trim', explode(',', $tagsStr))));
    }
}
