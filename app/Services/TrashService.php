<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Core\Config;
use App\Core\ConcurrencyGuard;
use App\Support\LogHelper;

class TrashService
{
    use LogHelper;
    private $db;
    private $auth;
    private $config;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->auth = new AuthService();
        $this->config = Config::getInstance();
    }

    public function listTrash()
    {
        $userId = $this->auth->getUserId();
        $items = $this->db->fetchAll(
            "SELECT * FROM trash WHERE user_id = ? ORDER BY deleted_at DESC LIMIT 200",
            [$userId]
        );

        foreach ($items as &$item) {
            $item['filesize_formatted'] = Security::formatSize($item['filesize']);
            $item['deleted_at_formatted'] = Security::formatTime($item['deleted_at']);
            $item['expire_at_formatted'] = Security::formatTime($item['expire_at']);
            $item['remaining_days'] = max(0, ceil(($item['expire_at'] - time()) / 86400));
        }

        return $items;
    }

    public function restore($trashId)
    {
        $userId = $this->auth->getUserId();
        $item = $this->db->fetch("SELECT * FROM trash WHERE id = ? AND user_id = ?", [$trashId, $userId]);

        if (!$item) {
            return ['success' => false, 'message' => '回收站项目不存在'];
        }

        $trashPath = TRASH_PATH . DIRECTORY_SEPARATOR . $item['file_id'] . '_' . basename($item['filepath']);
        $originalPath = FILES_PATH . DIRECTORY_SEPARATOR . $item['original_path'];

        if (!file_exists($trashPath)) {
            return ['success' => false, 'message' => '文件物理路径不存在，无法恢复'];
        }

        $restorePath = $originalPath;
        if (file_exists($originalPath)) {
            $uniquePath = $this->getUniqueRestorePath($item['original_path']);
            $restorePath = $uniquePath;
        }

        $dir = dirname($restorePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // ── 物理优先模式：先物理 rename 再 DB 事务 ──
        // 物理 rename 失败时无 DB 变更，安全回滚。
        // DB 事务失败时需把物理文件 rename 回回收站，补偿本身可能失败——
        // 此时记录 critical 告警，trash 记录保留供下次清理重试物理删除。
        if (!rename($trashPath, $restorePath)) {
            return ['success' => false, 'message' => '恢复文件失败'];
        }

        $relativePath = str_replace(FILES_PATH . DIRECTORY_SEPARATOR, '', $restorePath);
        $restoredFilename = basename($restorePath);

        $guard = ConcurrencyGuard::getInstance();
        try {
            $newFileId = $guard->transactionImmediate(function () use (
                $item, $userId, $relativePath, $restoredFilename, $trashId
            ) {
                $parentId = $item['parent_id'];
                if ($parentId > 0) {
                    $parentExists = $this->db->fetch("SELECT id FROM files WHERE id = ? AND user_id = ? AND is_dir = 1", [$parentId, $userId]);
                    if (!$parentExists) {
                        $parentId = 0;
                    }
                }

                // 事务内二次检查：防止并发恢复同名文件（唯一索引是最后防线）
                $conflict = $this->db->fetch(
                    "SELECT id FROM files WHERE user_id = ? AND parent_id = ? AND filename = ?",
                    [$userId, $parentId, $restoredFilename]
                );
                if ($conflict) {
                    throw new \RuntimeException('FILE_CONFLICT');
                }

                $now = time();
                $newFileId = (int)$this->db->insert('files', [
                    'user_id' => $userId,
                    'filename' => $restoredFilename,
                    'filepath' => $relativePath,
                    'filesize' => $item['filesize'],
                    'file_type' => $item['file_type'],
                    'mime_type' => $item['mime_type'],
                    'is_dir' => $item['is_dir'],
                    'parent_id' => $parentId,
                    'path_hash' => md5($relativePath),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->db->delete('trash', 'id = ? AND user_id = ?', [$trashId, $userId]);

                // 如果是目录，级联恢复子文件/子目录（在同一事务内）
                if ($item['is_dir']) {
                    $this->restoreChildren($item, (int)$newFileId, $userId);
                }

                return $newFileId;
            });
        } catch (\Throwable $e) {
            // 事务失败：把物理文件移回回收站，保持文件系统与 DB 一致
            $rollbackOk = false;
            if (file_exists($restorePath)) {
                $rollbackOk = @rename($restorePath, $trashPath);
            }
            if (!$rollbackOk) {
                // 补偿失败：物理文件留在 restorePath 但 DB 没有对应记录——
                // 这是个"孤儿文件"，下次清理需运维介入
                \App\Core\AsyncLogger::getInstance()->error(
                    'TrashService::restore: compensation rename failed, orphan file created',
                    [
                        'trash_id' => $trashId,
                        'trash_path' => $trashPath,
                        'restore_path' => $restorePath,
                        'original_path' => $item['original_path'],
                        'db_error' => $e->getMessage(),
                    ]
                );
            }
            $msg = ($e instanceof \RuntimeException && str_contains($e->getMessage(), 'FILE_CONFLICT'))
                ? '目标位置已存在同名文件'
                : '恢复失败：' . $e->getMessage();
            return ['success' => false, 'message' => $msg];
        }

        $this->logOperation('restore', $item['filename']);

        return ['success' => true, 'message' => '文件已恢复'];
    }

    /**
     * 级联恢复目录的子文件。
     *
     * 级联删除时子文件的物理存储随父目录一并移入回收站（rename），
     * 子文件在 trash 表中只有记录、没有独立物理文件。恢复时只需
     * 插入 files 记录并清理 trash 记录，物理文件随父目录一起恢复。
     */
    private function restoreChildren(array $parentItem, int $newParentId, int $userId): void
    {
        $prefix = $parentItem['original_path'] . DIRECTORY_SEPARATOR;
        $all = $this->db->fetchAll(
            "SELECT * FROM trash WHERE user_id = ? ORDER BY LENGTH(original_path) ASC",
            [$userId]
        );

        foreach ($all as $child) {
            if (!str_starts_with($child['original_path'], $prefix)) {
                continue;
            }

            // 子文件的物理位置由其父目录的 rename 一同恢复，无需移动
            $childParentId = 0;
            $childDir = dirname($child['original_path']);
            if ($childDir !== '.') {
                $parentRecord = $this->db->fetch(
                    "SELECT id FROM files WHERE user_id = ? AND filepath = ?",
                    [$userId, $childDir]
                );
                if ($parentRecord) {
                    $childParentId = (int)$parentRecord['id'];
                }
            }

            $now = time();
            $this->db->insert('files', [
                'user_id' => $userId,
                'filename' => $child['filename'],
                'filepath' => $child['original_path'],
                'filesize' => $child['filesize'],
                'file_type' => $child['file_type'],
                'mime_type' => $child['mime_type'],
                'is_dir' => $child['is_dir'],
                'parent_id' => $childParentId,
                'path_hash' => md5($child['original_path']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->db->delete('trash', 'id = ? AND user_id = ?', [$child['id'], $userId]);
        }
    }

    public function permanentDelete($trashId)
    {
        $userId = $this->auth->getUserId();
        $item = $this->db->fetch("SELECT * FROM trash WHERE id = ? AND user_id = ?", [$trashId, $userId]);

        if (!$item) {
            return ['success' => false, 'message' => '回收站项目不存在'];
        }

        $trashPath = TRASH_PATH . DIRECTORY_SEPARATOR . $item['file_id'] . '_' . basename($item['filepath']);

        // ── 先执行物理删除（事务外）：失败时无需回滚 DB ──
        if ($item['is_dir']) {
            $this->removeDir($trashPath);
        } else {
            if (file_exists($trashPath)) {
                @unlink($trashPath);
            }
        }

        // ── DB 操作放入事务，保证"删除 trash 记录 + 停用关联分享"原子性 ──
        $guard = ConcurrencyGuard::getInstance();
        try {
            $guard->transactionImmediate(function () use ($item, $userId, $trashId) {
                if ($item['is_dir']) {
                    // 级联删除子文件的回收站记录（物理文件已随父目录一同删除）
                    $this->db->delete(
                        'trash',
                        'user_id = ? AND original_path LIKE ?',
                        [$userId, $item['original_path'] . '/%']
                    );
                }
                $this->db->query(
                    'UPDATE shares SET is_active = 0 WHERE file_id = ?',
                    [$item['file_id']]
                );
                $this->db->delete('trash', 'id = ? AND user_id = ?', [$trashId, $userId]);
            });
        } catch (\Throwable $e) {
            // 物理已删但 DB 回滚：trash 记录仍在，下次清理会重试物理删除
            return ['success' => false, 'message' => '删除失败：' . $e->getMessage()];
        }

        $this->logOperation('permanent_delete', $item['filename']);

        return ['success' => true, 'message' => '文件已永久删除'];
    }

    public function emptyTrash()
    {
        $userId = $this->auth->getUserId();
        $items = $this->db->fetchAll("SELECT * FROM trash WHERE user_id = ?", [$userId]);

        if (empty($items)) {
            return ['success' => true, 'message' => '回收站已清空'];
        }

        // ── 物理删除：失败项记录到 $failedIds 以便事务内排除 ──
        $failedIds = [];
        foreach ($items as $item) {
            $trashPath = TRASH_PATH . DIRECTORY_SEPARATOR . $item['file_id'] . '_' . basename($item['filepath']);
            $deleted = true;
            if ($item['is_dir']) {
                if (is_dir($trashPath)) {
                    $this->removeDir($trashPath);
                    // removeDir 不返回成功标志，用目录是否仍存在判断
                    $deleted = !is_dir($trashPath);
                }
            } else {
                if (file_exists($trashPath)) {
                    $deleted = @unlink($trashPath);
                }
            }
            if (!$deleted) {
                $failedIds[] = (int)$item['id'];
            }
        }

        // ── DB 操作原子化：单次事务完成 trash 清理 + 关联分享停用 ──
        $guard = ConcurrencyGuard::getInstance();
        try {
            $guard->transactionImmediate(function () use ($userId, $items, $failedIds) {
                $fileIds = [];
                foreach ($items as $item) {
                    if (in_array((int)$item['id'], $failedIds, true)) {
                        continue; // 物理删除失败的项目保留 trash 记录
                    }
                    $fileIds[] = (int)$item['file_id'];
                }
                if (!empty($fileIds)) {
                    $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
                    $this->db->query(
                        "UPDATE shares SET is_active = 0 WHERE file_id IN ({$placeholders})",
                        $fileIds
                    );
                }

                // 删除物理已成功的 trash 记录
                if (empty($failedIds)) {
                    $this->db->delete('trash', 'user_id = ?', [$userId]);
                } else {
                    $placeholders = implode(',', array_fill(0, count($failedIds), '?'));
                    $this->db->query(
                        "DELETE FROM trash WHERE user_id = ? AND id NOT IN ({$placeholders})",
                        array_merge([$userId], $failedIds)
                    );
                }
            });
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => '清空回收站失败：' . $e->getMessage()];
        }

        $this->logOperation('empty_trash', '清空回收站');

        $message = empty($failedIds)
            ? '回收站已清空'
            : '部分文件无法删除，已跳过 ' . count($failedIds) . ' 项';

        return ['success' => true, 'message' => $message];
    }

    public function cleanExpired()
    {
        $items = $this->db->fetchAll("SELECT * FROM trash WHERE expire_at > 0 AND expire_at < ?", [time()]);

        $count = 0;
        foreach ($items as $item) {
            $trashPath = TRASH_PATH . DIRECTORY_SEPARATOR . $item['file_id'] . '_' . basename($item['filepath']);

            if ($item['is_dir']) {
                $this->removeDir($trashPath);
            } else {
                if (file_exists($trashPath)) {
                    unlink($trashPath);
                }
            }

            $this->db->delete('trash', 'id = ?', [$item['id']]);
            $count++;
        }

        return $count;
    }

    private function removeDir($dir)
    {
        if (!is_dir($dir)) return;

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function getUniqueRestorePath($originalPath)
    {
        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $originalPath;
        $dir = dirname($fullPath);
        $base = pathinfo($fullPath, PATHINFO_FILENAME);
        $ext = pathinfo($fullPath, PATHINFO_EXTENSION);

        $counter = 1;
        while (file_exists($fullPath)) {
            $newName = $ext ? "{$base} ({$counter}).{$ext}" : "{$base} ({$counter})";
            $fullPath = $dir . DIRECTORY_SEPARATOR . $newName;
            $counter++;
        }

        return $fullPath;
    }

}
