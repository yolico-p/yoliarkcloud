<?php

namespace App\Core;

use PDOException;

class SearchService
{
    private $db;
    private $cache;
    private $dbType;

    public function __construct(Database $db, QueryCache $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
        $this->dbType = $db->getDbType();
    }

    public function search($keyword, $userId, $limit = 50, $offset = 0)
    {
        try {
            $cacheKey = 'search:' . md5($keyword . $userId . $limit . $offset);
            $cached = $this->cache->get($cacheKey);

            if ($cached !== null) {
                return $cached;
            }

            $result = $this->executeSearch($keyword, $userId, $limit, $offset);
            $this->cache->set($cacheKey, $result, ['search', 'user:' . $userId]);
            return $result;

        } catch (PDOException $e) {
            return $this->searchFallback($keyword, $userId, $limit, $offset);
        }
    }

    public function searchCount($keyword, $userId)
    {
        try {
            $cacheKey = 'search_count:' . md5($keyword . $userId);
            $cached = $this->cache->get($cacheKey);

            if ($cached !== null) {
                return $cached;
            }

            $result = $this->executeSearchCount($keyword, $userId);
            $this->cache->set($cacheKey, $result, ['search', 'user:' . $userId]);
            return $result;

        } catch (PDOException $e) {
            return $this->searchFallbackCount($keyword, $userId);
        }
    }

    private function executeSearch($keyword, $userId, $limit, $offset)
    {
        $keyword = $this->escapeKeyword($keyword);
        if (empty($keyword)) {
            return [];
        }

        switch ($this->dbType) {
            case 'sqlite':
                $sql = "SELECT f.* FROM files_fts ft
                        JOIN files f ON ft.rowid = f.id
                        WHERE f.user_id = ? AND files_fts MATCH ?
                        ORDER BY rank LIMIT ? OFFSET ?";
                return $this->db->fetchAll($sql, [$userId, $keyword, $limit, $offset]);

            case 'mysql':
                $sql = "SELECT f.* FROM files f
                        WHERE f.user_id = ? AND MATCH(f.filename, f.description, f.tags) AGAINST(? IN NATURAL LANGUAGE MODE)
                        LIMIT ? OFFSET ?";
                return $this->db->fetchAll($sql, [$userId, $keyword, $limit, $offset]);

            case 'pgsql':
                $sql = "SELECT f.* FROM files f
                        WHERE f.user_id = ? AND f.filename ILIKE ?
                        LIMIT ? OFFSET ?";
                return $this->db->fetchAll($sql, [$userId, '%' . $keyword . '%', $limit, $offset]);

            default:
                return $this->searchFallback($keyword, $userId, $limit, $offset);
        }
    }

    private function executeSearchCount($keyword, $userId)
    {
        $keyword = $this->escapeKeyword($keyword);
        if (empty($keyword)) {
            return 0;
        }

        switch ($this->dbType) {
            case 'sqlite':
                $sql = "SELECT COUNT(*) as count FROM files_fts ft
                        JOIN files f ON ft.rowid = f.id
                        WHERE f.user_id = ? AND files_fts MATCH ?";
                $result = $this->db->fetch($sql, [$userId, $keyword]);
                return $result['count'];

            case 'mysql':
                $sql = "SELECT COUNT(*) as count FROM files f
                        WHERE f.user_id = ? AND MATCH(f.filename, f.description, f.tags) AGAINST(? IN NATURAL LANGUAGE MODE)";
                $result = $this->db->fetch($sql, [$userId, $keyword]);
                return $result['count'];

            case 'pgsql':
                $sql = "SELECT COUNT(*) as count FROM files f
                        WHERE f.user_id = ? AND f.filename ILIKE ?";
                $result = $this->db->fetch($sql, [$userId, '%' . $keyword . '%']);
                return $result['count'];

            default:
                return $this->searchFallbackCount($keyword, $userId);
        }
    }

    private function escapeKeyword($keyword)
    {
        $specialChars = ['"', '*', '(', ')', '+', '-', '^', '~', '&', '|', '!', ':'];
        foreach ($specialChars as $char) {
            $keyword = str_replace($char, ' ' . $char . ' ', $keyword);
        }
        $keyword = preg_replace('/\s+/', ' ', trim($keyword));
        return $keyword;
    }

    private function searchFallback($keyword, $userId, $limit, $offset)
    {
        $keyword = '%' . $keyword . '%';
        return $this->db->fetchAll(
            "SELECT * FROM files WHERE user_id = ? AND (filename LIKE ? OR description LIKE ? OR tags LIKE ?)
             ORDER BY filename LIMIT ? OFFSET ?",
            [$userId, $keyword, $keyword, $keyword, $limit, $offset]
        );
    }

    private function searchFallbackCount($keyword, $userId)
    {
        $keyword = '%' . $keyword . '%';
        $result = $this->db->fetch(
            "SELECT COUNT(*) as count FROM files WHERE user_id = ? AND (filename LIKE ? OR description LIKE ? OR tags LIKE ?)",
            [$userId, $keyword, $keyword, $keyword]
        );
        return $result['count'];
    }
}
