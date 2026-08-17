<?php

namespace App\Controllers\System;

use App\Controllers\BaseController;
use App\Core\Security;
use App\Core\Database;

class LogController extends BaseController
{
    public function list()
    {
        $this->requireAuth();

        $result = $this->fetchLogs([
            'page' => intval($this->input('page', 1)),
            'page_size' => intval($this->input('page_size', 50)),
            'category' => $this->input('category', ''),
            'severity' => $this->input('severity', ''),
            'start_date' => $this->input('start_date', ''),
            'end_date' => $this->input('end_date', ''),
            'keyword' => $this->input('keyword', ''),
        ]);

        Security::jsonOutput($result);
    }

    public function stats()
    {
        $this->requireAuth();

        $userId = $this->getUserId();

        $totalLogs = $this->db->fetch("SELECT COUNT(*) as count FROM operation_logs WHERE user_id = ?", [$userId]);
        $categoryStats = $this->db->fetchAll(
            "SELECT category, COUNT(*) as count FROM operation_logs WHERE user_id = ? GROUP BY category ORDER BY count DESC",
            [$userId]
        );
        $severityStats = $this->db->fetchAll(
            "SELECT severity, COUNT(*) as count FROM operation_logs WHERE user_id = ? GROUP BY severity",
            [$userId]
        );

        Security::jsonOutput([
            'success' => true,
            'total' => $totalLogs['count'],
            'by_category' => $categoryStats,
            'by_severity' => $severityStats,
        ]);
    }

    public function clear()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $this->db->delete('operation_logs', 'user_id = ?', [$this->getUserId()]);

        $this->logOperation('clear_logs', '清除操作日志');

        $this->success('日志已清除');
    }

    public function operationLogs()
    {
        $this->requireAuth();

        $result = $this->fetchLogs([
            'page' => intval($this->input('page', 1)),
            'page_size' => intval($this->input('page_size', 50)),
            'category' => $this->input('category', ''),
            'severity' => $this->input('severity', ''),
            'start_date' => $this->input('start_date', ''),
            'end_date' => $this->input('end_date', ''),
            'keyword' => $this->input('keyword', ''),
        ]);

        Security::jsonOutput($result);
    }

    /**
     * 查询操作日志的公共逻辑（供 list / operationLogs 复用）。
     *
     * @param array $params 查询参数：page, page_size, category, severity,
     *                      start_date, end_date, keyword
     * @return array        含 success/logs/total/page/page_size 的结果数组
     */
    private function fetchLogs(array $params): array
    {
        $page = intval($params['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }
        $pageSize = intval($params['page_size'] ?? 50);
        if ($pageSize < 1) {
            $pageSize = 50;
        }
        $category = $params['category'] ?? '';
        $severity = $params['severity'] ?? '';
        $startDate = $params['start_date'] ?? '';
        $endDate = $params['end_date'] ?? '';
        $keyword = $params['keyword'] ?? '';

        $where = "user_id = ?";
        $bindings = [$this->getUserId()];

        if (!empty($category)) {
            $where .= " AND category = ?";
            $bindings[] = $category;
        }
        if (!empty($severity)) {
            $where .= " AND severity = ?";
            $bindings[] = $severity;
        }
        if (!empty($startDate)) {
            $where .= " AND created_at >= ?";
            $bindings[] = strtotime($startDate);
        }
        if (!empty($endDate)) {
            $where .= " AND created_at <= ?";
            $bindings[] = strtotime($endDate . ' 23:59:59');
        }
        if (!empty($keyword)) {
            $where .= " AND (action LIKE ? OR detail LIKE ? OR target LIKE ?)";
            $bindings[] = '%' . $keyword . '%';
            $bindings[] = '%' . $keyword . '%';
            $bindings[] = '%' . $keyword . '%';
        }

        $countResult = $this->db->fetch("SELECT COUNT(*) as count FROM operation_logs WHERE {$where}", $bindings);
        $total = $countResult['count'];

        $sql = "SELECT * FROM operation_logs WHERE {$where} ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $bindings[] = $pageSize;
        $bindings[] = ($page - 1) * $pageSize;

        $logs = $this->db->fetchAll($sql, $bindings);

        foreach ($logs as &$log) {
            $log['created_at_formatted'] = Security::formatTime($log['created_at']);
        }

        return [
            'success' => true,
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    public function logStatistics()
    {
        $this->requireAuth();

        $userId = $this->getUserId();
        $days = intval($this->input('days', 7));
        $since = time() - ($days * 86400);

        $totalLogs = $this->db->fetch("SELECT COUNT(*) as count FROM operation_logs WHERE user_id = ?", [$userId]);
        $recentLogs = $this->db->fetch(
            "SELECT COUNT(*) as count FROM operation_logs WHERE user_id = ? AND created_at >= ?",
            [$userId, $since]
        );

        $categoryStats = $this->db->fetchAll(
            "SELECT category, COUNT(*) as count FROM operation_logs WHERE user_id = ? AND created_at >= ? GROUP BY category ORDER BY count DESC",
            [$userId, $since]
        );
        $severityStats = $this->db->fetchAll(
            "SELECT severity, COUNT(*) as count FROM operation_logs WHERE user_id = ? AND created_at >= ? GROUP BY severity",
            [$userId, $since]
        );

        $dbType = $this->getDbType();
        if ($dbType === 'mysql') {
            $dailyStats = $this->db->fetchAll(
                "SELECT DATE_FORMAT(FROM_UNIXTIME(created_at), '%Y-%m-%d') as date, COUNT(*) as count
                 FROM operation_logs WHERE user_id = ? AND created_at >= ?
                 GROUP BY DATE_FORMAT(FROM_UNIXTIME(created_at), '%Y-%m-%d') ORDER BY date",
                [$userId, $since]
            );
        } elseif ($dbType === 'pgsql') {
            $dailyStats = $this->db->fetchAll(
                "SELECT TO_CHAR(TO_TIMESTAMP(created_at), 'YYYY-MM-DD') as date, COUNT(*) as count
                 FROM operation_logs WHERE user_id = ? AND created_at >= ?
                 GROUP BY TO_CHAR(TO_TIMESTAMP(created_at), 'YYYY-MM-DD') ORDER BY date",
                [$userId, $since]
            );
        } else {
            $dailyStats = $this->db->fetchAll(
                "SELECT DATE(created_at, 'unixepoch') as date, COUNT(*) as count
                 FROM operation_logs WHERE user_id = ? AND created_at >= ?
                 GROUP BY DATE(created_at, 'unixepoch') ORDER BY date",
                [$userId, $since]
            );
        }

        $topActions = $this->db->fetchAll(
            "SELECT action, COUNT(*) as count FROM operation_logs WHERE user_id = ? AND created_at >= ? GROUP BY action ORDER BY count DESC LIMIT 10",
            [$userId, $since]
        );

        Security::jsonOutput([
            'success' => true,
            'total' => $totalLogs['count'],
            'recent' => $recentLogs['count'],
            'by_category' => $categoryStats,
            'by_severity' => $severityStats,
            'daily_stats' => $dailyStats,
            'top_actions' => $topActions,
        ]);
    }

    private function getDbType()
    {
        try {
            // 从 Config 获取数据库类型
            $config = \App\Core\Config::getInstance();
            return $config->get('database.type', 'sqlite');
        } catch (\Throwable $e) {
            return 'sqlite';
        }
    }

    private function logOperation($action, $detail = '')
    {
        $userId = $this->getUserId();
        if (!$userId) return;

        $this->db->insert('operation_logs', [
            'user_id' => $userId,
            'action' => $action,
            'category' => 'system',
            'severity' => 'warning',
            'detail' => $detail,
            'ip' => Security::getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => time(),
        ]);
    }
}
