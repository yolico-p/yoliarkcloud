<?php

namespace App\Services;

use App\Core\Database;

class AISessionService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ── 会话持久化 ──

    public function createSession($context = null)
    {
        $userId = $_SESSION['user_id'] ?? 0;
        if (!$userId) return ['success' => false, 'message' => '未登录'];

        $sessionId = 'sess_' . bin2hex(random_bytes(12));
        $now = time();

        $this->db->insert('ai_sessions', [
            'id' => $sessionId,
            'user_id' => $userId,
            'title' => '新对话',
            'context' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['success' => true, 'session_id' => $sessionId];
    }

    public function listSessions($page = 1, $pageSize = 20)
    {
        $userId = $_SESSION['user_id'] ?? 0;
        if (!$userId) return ['success' => false, 'message' => '未登录'];

        $offset = ($page - 1) * $pageSize;
        $sessions = $this->db->fetchAll(
            "SELECT id, title, status, created_at, updated_at FROM ai_sessions WHERE user_id = ? ORDER BY updated_at DESC LIMIT ? OFFSET ?",
            [$userId, $pageSize, $offset]
        );

        $total = $this->db->fetch("SELECT COUNT(*) as cnt FROM ai_sessions WHERE user_id = ?", [$userId])['cnt'] ?? 0;

        return [
            'success' => true,
            'sessions' => $sessions,
            'total' => $total,
            'page' => $page,
            'has_more' => $offset + count($sessions) < $total,
        ];
    }

    public function getSessionMessages($sessionId)
    {
        $userId = $_SESSION['user_id'] ?? 0;
        if (!$userId) return ['success' => false, 'message' => '未登录'];

        $session = $this->db->fetch("SELECT * FROM ai_sessions WHERE id = ? AND user_id = ?", [$sessionId, $userId]);
        if (!$session) return ['success' => false, 'message' => '会话不存在'];

        $messages = $this->db->fetchAll(
            "SELECT id, role, content, tool_calls, tool_call_id, metadata, created_at FROM ai_messages WHERE session_id = ? ORDER BY id ASC",
            [$sessionId]
        );

        // 解码 JSON 字段
        foreach ($messages as &$msg) {
            if (!empty($msg['tool_calls'])) {
                $msg['tool_calls'] = json_decode($msg['tool_calls'], true) ?: [];
            }
            if (!empty($msg['metadata'])) {
                $msg['metadata'] = json_decode($msg['metadata'], true) ?: [];
            }
        }

        return [
            'success' => true,
            'session' => $session,
            'messages' => $messages,
        ];
    }

    public function deleteSession($sessionId)
    {
        $userId = $_SESSION['user_id'] ?? 0;
        if (!$userId) return ['success' => false, 'message' => '未登录'];

        $session = $this->db->fetch("SELECT id FROM ai_sessions WHERE id = ? AND user_id = ?", [$sessionId, $userId]);
        if (!$session) return ['success' => false, 'message' => '会话不存在'];

        $this->db->delete('ai_messages', 'session_id = ?', [$sessionId]);
        $this->db->delete('ai_sessions', 'id = ?', [$sessionId]);

        return ['success' => true];
    }

    public function updateSessionTitle($sessionId, $title)
    {
        $userId = $_SESSION['user_id'] ?? 0;
        if (!$userId) return ['success' => false, 'message' => '未登录'];

        $title = mb_substr(trim($title), 0, 50, 'UTF-8');
        if (empty($title)) $title = '新对话';

        $this->db->update('ai_sessions', ['title' => $title, 'updated_at' => time()], 'id = ? AND user_id = ?', [$sessionId, $userId]);

        return ['success' => true, 'title' => $title];
    }

    public function saveMessage($sessionId, $role, $content, $toolCalls = null, $toolCallId = '', $metadata = null)
    {
        $this->db->insert('ai_messages', [
            'session_id' => $sessionId,
            'role' => $role,
            'content' => is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE),
            'tool_calls' => $toolCalls ? json_encode($toolCalls, JSON_UNESCAPED_UNICODE) : '',
            'tool_call_id' => $toolCallId,
            'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : '',
            'created_at' => time(),
        ]);

        $this->db->update('ai_sessions', ['updated_at' => time()], 'id = ?', [$sessionId]);
    }

    public function saveMessagePublic($sessionId, $role, $content)
    {
        $this->saveMessage($sessionId, $role, $content);
    }

    public function loadSessionMessages($sessionId)
    {
        $userId = $_SESSION['user_id'] ?? 0;
        if (!$userId) return [];

        $session = $this->db->fetch("SELECT id FROM ai_sessions WHERE id = ? AND user_id = ?", [$sessionId, $userId]);
        if (!$session) return [];

        $rows = $this->db->fetchAll(
            "SELECT role, content, tool_calls, tool_call_id FROM ai_messages WHERE session_id = ? ORDER BY id ASC",
            [$sessionId]
        );

        $messages = [];
        foreach ($rows as $row) {
            $msg = ['role' => $row['role'], 'content' => $row['content']];
            if (!empty($row['tool_calls'])) {
                $msg['tool_calls'] = json_decode($row['tool_calls'], true) ?: [];
            }
            if (!empty($row['tool_call_id'])) {
                $msg['tool_call_id'] = $row['tool_call_id'];
            }
            $messages[] = $msg;
        }

        return $messages;
    }

    /**
     * 更新会话中待确认工具消息为实际执行结果
     */
    public function updateToolMessageResult($sessionId, $toolCallId, $result)
    {
        $this->db->update(
            'ai_messages',
            [
                'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'metadata' => '',
            ],
            'session_id = ? AND tool_call_id = ?',
            [$sessionId, $toolCallId]
        );
    }
}
