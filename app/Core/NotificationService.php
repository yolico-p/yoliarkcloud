<?php

namespace App\Core;

use App\Core\Database;

class NotificationService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 创建一条通知。
     *
     * @param int $userId 用户ID
     * @param string $type 通知类型：agent_complete/agent_failed/scheduled_task/system
     * @param string $title 通知标题
     * @param string $body 通知内容（可选 JSON）
     * @param string $relatedId 关联ID（session_id / task_id）
     * @return int 通知ID
     */
    public function notify(int $userId, string $type, string $title, string $body = '', string $relatedId = ''): int
    {
        return $this->db->insert('notifications', [
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'related_id' => $relatedId,
            'is_read' => 0,
            'created_at' => time(),
        ]);
    }

    /**
     * 获取用户未读通知。
     *
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public function getUnread(int $userId, int $limit = 20): array
    {
        return $this->db->fetchAll(
            "SELECT id, type, title, body, related_id, is_read, created_at
             FROM notifications
             WHERE user_id = ? AND is_read = 0
             ORDER BY created_at DESC
             LIMIT ?",
            [$userId, $limit]
        );
    }

    /**
     * 获取用户所有通知（分页）。
     *
     * @param int $userId
     * @param int $page
     * @param int $pageSize
     * @return array
     */
    public function getAll(int $userId, int $page = 1, int $pageSize = 20): array
    {
        $offset = ($page - 1) * $pageSize;
        $notifications = $this->db->fetchAll(
            "SELECT id, type, title, body, related_id, is_read, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $pageSize, $offset]
        );

        $total = $this->db->fetch(
            "SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ?",
            [$userId]
        )['cnt'] ?? 0;

        return [
            'notifications' => $notifications,
            'total' => (int)$total,
            'page' => $page,
            'has_more' => $offset + count($notifications) < $total,
        ];
    }

    /**
     * 标记单条通知已读。
     *
     * @param int $notificationId
     * @param int $userId
     * @return bool
     */
    public function markRead(int $notificationId, int $userId): bool
    {
        $affected = $this->db->update(
            'notifications',
            ['is_read' => 1],
            'id = ? AND user_id = ?',
            [$notificationId, $userId]
        );
        return $affected > 0;
    }

    /**
     * 标记所有通知已读。
     *
     * @param int $userId
     * @return int 标记数量
     */
    public function markAllRead(int $userId): int
    {
        return $this->db->update(
            'notifications',
            ['is_read' => 1],
            'user_id = ? AND is_read = 0',
            [$userId]
        );
    }

    /**
     * 获取未读通知数量（用于角标）。
     *
     * @param int $userId
     * @return int
     */
    public function getUnreadCount(int $userId): int
    {
        $row = $this->db->fetch(
            "SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND is_read = 0",
            [$userId]
        );
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * 清理过期通知（30天前已读的通知）。
     *
     * @return int 删除数量
     */
    public function cleanupOld(): int
    {
        $threshold = time() - 86400 * 30;
        return $this->db->delete('notifications', 'is_read = 1 AND created_at < ?', [$threshold]);
    }
}
