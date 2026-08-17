<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Core\SearchService;
use App\Support\FileTypeTrait;

class FileQueryService
{
    use FileTypeTrait;

    private $db;
    private $auth;
    private $search;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->auth = new AuthService();
        $this->search = new SearchService($this->db, $this->db->getQueryCache());
    }

    public function listFiles($parentId = 0, $sortBy = 'name', $sortOrder = 'asc', $page = 1, $pageSize = 100)
    {
        $userId = $this->auth->getUserId();

        $allowedSorts = ['name' => 'filename', 'size' => 'filesize', 'time' => 'created_at', 'type' => 'file_type', 'custom' => 'sort_order'];
        $allowedDirs = ['asc', 'desc'];
        $sortColumn = isset($allowedSorts[$sortBy]) ? $allowedSorts[$sortBy] : 'filename';
        $sortDir = in_array(strtolower($sortOrder), $allowedDirs) ? strtoupper($sortOrder) : 'ASC';

        $secondarySort = $sortColumn === 'sort_order' ? ', filename ASC' : '';

        if ($pageSize <= 0) {
            $files = $this->db->fetchCached(
                "SELECT * FROM files WHERE user_id = ? AND parent_id = ?
                 ORDER BY is_dir DESC, {$sortColumn} {$sortDir}{$secondarySort}",
                [$userId, $parentId],
                ['files', 'user:' . $userId, 'parent:' . $parentId]
            );
        } else {
            $offset = ($page - 1) * $pageSize;
            $files = $this->db->fetchCached(
                "SELECT * FROM files WHERE user_id = ? AND parent_id = ?
                 ORDER BY is_dir DESC, {$sortColumn} {$sortDir}{$secondarySort}
                 LIMIT ? OFFSET ?",
                [$userId, $parentId, $pageSize, $offset],
                ['files', 'user:' . $userId, 'parent:' . $parentId]
            );
        }

        foreach ($files as &$file) {
            $file['filesize_formatted'] = Security::formatSize($file['filesize']);
            $file['created_at_formatted'] = Security::formatTime($file['created_at']);
            $file['updated_at_formatted'] = Security::formatTime($file['updated_at']);
            $file['icon'] = $this->getFileIcon($file);
            $file['tags'] = $this->parseTags($file['tags'] ?? '');
            $file['thumbnail_url'] = $this->buildThumbnailUrl($file);
        }

        return $files;
    }

    public function getFileCount($parentId = 0)
    {
        $userId = $this->auth->getUserId();
        $result = $this->db->fetch(
            "SELECT COUNT(*) as count FROM files WHERE user_id = ? AND parent_id = ?",
            [$userId, $parentId]
        );
        return $result['count'];
    }

    public function getFileById($fileId)
    {
        $userId = $this->auth->getUserId();
        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);

        if ($file) {
            $file['filesize_formatted'] = Security::formatSize($file['filesize']);
            $file['created_at_formatted'] = Security::formatTime($file['created_at']);
            $file['updated_at_formatted'] = Security::formatTime($file['updated_at']);
            $file['icon'] = $this->getFileIcon($file);
            $file['tags'] = $this->parseTags($file['tags'] ?? '');
            $file['thumbnail_url'] = $this->buildThumbnailUrl($file);
        }

        return $file;
    }

    public function getBreadcrumb($parentId)
    {
        if ($parentId <= 0) {
            return [];
        }

        $userId = $this->auth->getUserId();

        try {
            $rows = $this->db->fetchAll(
                "WITH RECURSIVE path(id, filename, parent_id, lvl) AS (
                    SELECT id, filename, parent_id, 0 FROM files WHERE id = ? AND user_id = ?
                    UNION ALL
                    SELECT f.id, f.filename, f.parent_id, p.lvl + 1 FROM files f
                    INNER JOIN path p ON f.id = p.parent_id
                    WHERE f.user_id = ?
                )
                SELECT id, filename, parent_id FROM path WHERE id > 0 ORDER BY lvl DESC",
                [$parentId, $userId, $userId]
            );
        } catch (\Throwable $e) {
            $rows = [];
            $currentId = $parentId;
            $visited = [];
            while ($currentId > 0) {
                if (isset($visited[$currentId])) break;
                $visited[$currentId] = true;
                $row = $this->db->fetch(
                    "SELECT id, filename, parent_id FROM files WHERE id = ? AND user_id = ?",
                    [$currentId, $userId]
                );
                if (!$row) break;
                array_unshift($rows, $row);
                $currentId = (int)$row['parent_id'];
            }
        }

        return $rows;
    }

    public function getAllFoldersTree()
    {
        $userId = $this->auth->getUserId();
        $folders = $this->db->fetchAll(
            "SELECT id, filename, parent_id FROM files WHERE user_id = ? AND is_dir = 1 ORDER BY parent_id, filename",
            [$userId]
        );

        $tree = [];
        $map = [];

        foreach ($folders as $folder) {
            $map[$folder['id']] = $folder;
            $map[$folder['id']]['children'] = [];
        }

        foreach ($map as $id => $folder) {
            if ($folder['parent_id'] == 0) {
                $tree[] = &$map[$id];
            } else {
                if (isset($map[$folder['parent_id']])) {
                    $map[$folder['parent_id']]['children'][] = &$map[$id];
                }
            }
        }

        return $tree;
    }

    public function getStorageInfo()
    {
        $user = $this->auth->getUser();
        if (!$user) return null;

        $used = $this->auth->getStorageUsed();
        $total = $user['storage_limit'];

        return [
            'used' => $used,
            'total' => $total,
            'used_formatted' => Security::formatSize($used),
            'total_formatted' => Security::formatSize($total),
            'percentage' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
        ];
    }

    public function getFileStats()
    {
        $userId = $this->auth->getUserId();

        $totalFiles = $this->db->fetch("SELECT COUNT(*) as count FROM files WHERE user_id = ? AND is_dir = 0", [$userId]);
        $totalFolders = $this->db->fetch("SELECT COUNT(*) as count FROM files WHERE user_id = ? AND is_dir = 1", [$userId]);
        $totalShares = $this->db->fetch("SELECT COUNT(*) as count FROM shares WHERE user_id = ? AND is_active = 1", [$userId]);
        $trashCount = $this->db->fetch("SELECT COUNT(*) as count FROM trash WHERE user_id = ?", [$userId]);

        $typeStats = $this->db->fetchAll(
            "SELECT file_type, COUNT(*) as count, SUM(filesize) as total_size FROM files WHERE user_id = ? AND is_dir = 0 GROUP BY file_type ORDER BY total_size DESC LIMIT 10",
            [$userId]
        );

        return [
            'total_files' => $totalFiles['count'],
            'total_folders' => $totalFolders['count'],
            'total_shares' => $totalShares['count'],
            'trash_count' => $trashCount['count'],
            'type_stats' => $typeStats,
        ];
    }

    public function searchFiles($keyword, $type = 'all', $page = 1, $pageSize = 50, $sortBy = 'name', $sortOrder = 'asc')
    {
        $userId = $this->auth->getUserId();
        $offset = ($page - 1) * $pageSize;

        if (strlen($keyword) >= 3) {
            $files = $this->search->search($keyword, $userId, $pageSize, $offset);
        } else {
            $files = $this->searchFilesLegacy($keyword, $type, $userId, $pageSize, $offset);
        }

        if (empty($files)) {
            $files = $this->searchFilesLegacy($keyword, $type, $userId, $pageSize, $offset);
        }

        return $this->formatFilesResult($files);
    }

    public function getSearchCount($keyword, $type = 'all')
    {
        $userId = $this->auth->getUserId();

        if (strlen($keyword) >= 3) {
            $count = $this->search->searchCount($keyword, $userId);
            if ($count > 0) {
                return $count;
            }
        }

        return $this->getSearchCountLegacy($keyword, $type, $userId);
    }

    private function searchFilesLegacy($keyword, $type, $userId, $pageSize, $offset)
    {
        $keyword = '%' . $keyword . '%';
        $sql = "SELECT * FROM files WHERE user_id = ? AND LOWER(filename) LIKE LOWER(?)";
        $params = [$userId, $keyword];

        if ($type !== 'all') {
            $typeMap = [
                'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'tif'],
                'video' => ['mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm'],
                'audio' => ['mp3', 'wav', 'flac', 'aac', 'ogg', 'wma', 'm4a', 'aiff', 'aif', 'opus', 'ape', 'alac', 'ra', 'ram', 'ac3', 'amr', 'mid', 'midi'],
                'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md'],
                'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
            ];

            if (isset($typeMap[$type])) {
                $placeholders = implode(',', array_fill(0, count($typeMap[$type]), '?'));
                $sql .= " AND file_type IN ({$placeholders})";
                $params = array_merge($params, $typeMap[$type]);
            }
        }

        $sql .= " ORDER BY filename LIMIT ? OFFSET ?";
        $params = array_merge($params, [$pageSize, $offset]);

        return $this->db->fetchCached($sql, $params, ['files']);
    }

    private function getSearchCountLegacy($keyword, $type, $userId)
    {
        $keyword = '%' . $keyword . '%';
        $sql = "SELECT COUNT(*) as count FROM files WHERE user_id = ? AND LOWER(filename) LIKE LOWER(?)";
        $params = [$userId, $keyword];

        if ($type !== 'all') {
            $typeMap = [
                'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'tif'],
                'video' => ['mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm'],
                'audio' => ['mp3', 'wav', 'flac', 'aac', 'ogg', 'wma', 'm4a', 'aiff', 'aif', 'opus', 'ape', 'alac', 'ra', 'ram', 'ac3', 'amr', 'mid', 'midi'],
                'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md'],
                'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
            ];

            if (isset($typeMap[$type])) {
                $placeholders = implode(',', array_fill(0, count($typeMap[$type]), '?'));
                $sql .= " AND file_type IN ({$placeholders})";
                $params = array_merge($params, $typeMap[$type]);
            }
        }

        $result = $this->db->fetch($sql, $params);
        return $result['count'];
    }

    private function formatFilesResult($files)
    {
        foreach ($files as &$file) {
            $file['filesize_formatted'] = Security::formatSize($file['filesize']);
            $file['created_at_formatted'] = Security::formatTime($file['created_at']);
            $file['icon'] = $this->getFileIcon($file);
            $file['tags'] = $this->parseTags($file['tags'] ?? '');
            $file['thumbnail_url'] = $this->buildThumbnailUrl($file);
        }

        return $files;
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
