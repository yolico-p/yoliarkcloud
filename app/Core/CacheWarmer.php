<?php

namespace App\Core;

class CacheWarmer
{
    private $db;
    private $cache;
    private $search;
    private $warmed = false;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->cache = $this->db->getQueryCache();
        $this->search = new SearchService($this->db, $this->cache);
    }

    public function warm()
    {
        if ($this->warmed) {
            return;
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return;
        }

        $this->warmUserCache($userId);
        $this->warmConfigCache();
        $this->warmed = true;
    }

    private function warmUserCache($userId)
    {
        $queries = [
            "SELECT * FROM files WHERE user_id = ? AND parent_id = 0 ORDER BY filename LIMIT 100",
            "SELECT COUNT(*) as count FROM files WHERE user_id = ? AND is_dir = 0",
            "SELECT COUNT(*) as count FROM files WHERE user_id = ? AND is_dir = 1",
            "SELECT * FROM shares WHERE user_id = ? AND is_active = 1 LIMIT 50",
        ];

        foreach ($queries as $sql) {
            $this->db->fetchCached($sql, [$userId], ['user:' . $userId, 'files']);
        }
    }

    private function warmConfigCache()
    {
        $configKeys = [
            'site_name',
            'max_upload_size',
            'trash_retention_days',
            'allowed_extensions',
            'blocked_extensions',
        ];

        foreach ($configKeys as $key) {
            Config::getInstance()->get($key);
        }
    }

    public function warmFileList($userId, $parentId = 0)
    {
        $this->db->fetchCached(
            "SELECT * FROM files WHERE user_id = ? AND parent_id = ? ORDER BY filename LIMIT 100",
            [$userId, $parentId],
            ['files', 'user:' . $userId, 'parent:' . $parentId]
        );
    }

    public function warmSearchResults($userId, $popularKeywords = [])
    {
        if (empty($popularKeywords)) {
            $popularKeywords = ['pdf', 'doc', 'image', 'video'];
        }

        foreach ($popularKeywords as $keyword) {
            if (strlen($keyword) >= 3) {
                $this->search->search($keyword, $userId, 10, 0);
            }
        }
    }

    public function cleanupStaleCache()
    {
        $this->cache->clear();
    }
}
