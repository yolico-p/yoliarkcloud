<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Core\ConcurrencyGuard;
use App\Support\FileTypeTrait;

class FileAccessService
{
    use FileTypeTrait;

    private $db;
    private $auth;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->auth = new AuthService();
    }

    public function recordAccess($fileId)
    {
        $userId = $this->auth->getUserId();
        if (!$userId) return;

        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);
        if (!$file) return;

        ConcurrencyGuard::getInstance()->upsert(
            'recent_access',
            [
                'user_id' => $userId,
                'file_id' => $fileId,
                'filename' => $file['filename'],
                'filepath' => $file['filepath'],
                'filesize' => $file['filesize'],
                'file_type' => $file['file_type'],
                'is_dir' => $file['is_dir'],
                'accessed_at' => time(),
            ],
            ['user_id', 'file_id'],
            ['filename', 'filepath', 'filesize', 'file_type', 'is_dir', 'accessed_at']
        );

        $count = $this->db->fetch("SELECT COUNT(*) as count FROM recent_access WHERE user_id = ?", [$userId]);
        if ($count['count'] > 100) {
            $excess = (int)$count['count'] - 100;
            $this->db->query(
                "DELETE FROM recent_access WHERE user_id = ? ORDER BY accessed_at ASC LIMIT ?",
                [$userId, $excess]
            );
        }
    }

    public function getRecentAccess()
    {
        $userId = $this->auth->getUserId();

        $items = $this->db->fetchAll(
            "SELECT r.*, f.content_hash, f.updated_at
             FROM recent_access r
             LEFT JOIN files f ON f.id = r.file_id AND f.user_id = r.user_id
             WHERE r.user_id = ?
             ORDER BY r.accessed_at DESC
             LIMIT 100",
            [$userId]
        );

        foreach ($items as &$item) {
            $item['filesize_formatted'] = Security::formatSize($item['filesize']);
            $item['accessed_at_formatted'] = Security::formatTime($item['accessed_at']);
            $item['icon'] = $this->getFileIcon($item);
            $item['thumbnail_url'] = $this->buildThumbnailUrl($item);
        }

        return $items;
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
}
