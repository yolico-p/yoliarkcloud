<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Core\Config;

use App\Core\ConcurrencyGuard;
use App\Support\LogHelper;
use App\Support\FileTypeTrait;

class FileManagerService
{
    use LogHelper;
    use FileTypeTrait;


    private $db;
    private $auth;
    private $config;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->auth = new AuthService();
        $this->config = Config::getInstance();

        $this->ensureDirectories();
    }

    public function getAuthService()
    {
        return $this->auth;
    }


    private function ensureDirectories()
    {
        $dirs = [FILES_PATH, TRASH_PATH, UPLOAD_PATH];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $protectFiles = [
            FILES_PATH . DIRECTORY_SEPARATOR . '.htaccess',
            TRASH_PATH . DIRECTORY_SEPARATOR . '.htaccess',
            UPLOAD_PATH . DIRECTORY_SEPARATOR . '.htaccess',
            DATA_PATH . DIRECTORY_SEPARATOR . '.htaccess',
        ];

        $htaccessContent = "Deny from all\n";

        foreach ($protectFiles as $file) {
            if (!file_exists($file)) {
                file_put_contents($file, $htaccessContent);
            }
        }
    }


    /** @deprecated Use FileQueryService instead */
    public function listFiles($parentId = 0, $sortBy = 'name', $sortOrder = 'asc', $page = 1, $pageSize = 100)
    {
        return (new FileQueryService())->listFiles($parentId, $sortBy, $sortOrder, $page, $pageSize);
    }

    /** @deprecated Use FileQueryService instead */
    public function getFileCount($parentId = 0)
    {
        return (new FileQueryService())->getFileCount($parentId);
    }

    public function createFolder($parentId, $folderName)
    {
        $userId = $this->auth->getUserId();
        $folderName = Security::sanitizeFilename($folderName);

        if (empty($folderName)) {
            return ['success' => false, 'message' => '文件夹名称不能为空'];
        }

        $parent = $this->getFileById($parentId);
        $parentPath = $parent ? $parent['filepath'] : '';

        $folderPath = $parentPath ? $parentPath . DIRECTORY_SEPARATOR . $folderName : $folderName;
        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $folderPath;

        // 事务内检查同名并占位 DB 记录，消除 TOCTOU 竞态
        $now = time();
        $folderId = 0;
        try {
            ConcurrencyGuard::getInstance()->transactionImmediate(function () use (
                $userId, $parentId, $folderName, $folderPath, $now, &$folderId
            ) {
                $existing = $this->db->fetch(
                    "SELECT id FROM files WHERE user_id = ? AND parent_id = ? AND LOWER(filename) = LOWER(?)",
                    [$userId, $parentId, $folderName]
                );
                if ($existing) {
                    throw new \RuntimeException('FOLDER_EXISTS');
                }

                $folderId = (int)$this->db->insert('files', [
                    'user_id' => $userId,
                    'filename' => $folderName,
                    'filepath' => $folderPath,
                    'filesize' => 0,
                    'file_type' => 'folder',
                    'mime_type' => '',
                    'is_dir' => 1,
                    'parent_id' => $parentId,
                    'path_hash' => md5($folderPath),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        } catch (\Throwable $e) {
            $msg = ($e instanceof \RuntimeException && str_contains($e->getMessage(), 'FOLDER_EXISTS'))
                ? '同名文件夹已存在'
                : '文件夹创建失败：' . $e->getMessage();
            return ['success' => false, 'message' => $msg];
        }

        // 事务外创建物理目录；失败则回滚刚插入的 DB 记录
        if (!is_dir($fullPath)) {
            if (!mkdir($fullPath, 0755, true)) {
                $this->db->delete('files', 'id = ? AND user_id = ?', [$folderId, $userId]);
                return ['success' => false, 'message' => '文件夹创建失败'];
            }
        }

        $this->logOperation('create_folder', $folderName);

        $this->db->invalidateTableCache("files");
        $this->db->clearCacheByTags(['parent:' . $parentId]);

        return ['success' => true, 'message' => '文件夹创建成功', 'file_id' => $folderId];
    }

    /**
     * 确保目录路径存在：根据相对路径从 parentId 开始逐层创建文件夹。
     * 用于拖拽上传文件夹时保持原有目录结构。
     *
     * @param int $parentId 起始父目录 ID
     * @param string $relativePath 相对路径，如 "Wallpapers/Nature/Sunset"
     * @return int 最终目录的 ID（等于 parentId 如果路径为空）
     */
    public function ensureFolderPath($parentId, $relativePath)
    {
        if (empty($relativePath)) {
            return $parentId;
        }

        // 规范化路径：去除首尾斜杠、连续斜杠
        $relativePath = trim($relativePath, '/\\');
        $relativePath = preg_replace('#[/\\\\]+#', '/', $relativePath);
        if ($relativePath === '') {
            return $parentId;
        }

        $parts = explode('/', $relativePath);
        $currentParentId = $parentId;
        $userId = $this->auth->getUserId();

        foreach ($parts as $folderName) {
            $folderName = Security::sanitizeFilename($folderName);
            if ($folderName === '' || $folderName === '.' || $folderName === '..') {
                continue;
            }

            // 查找当前层级是否已存在同名文件夹
            $existing = $this->db->fetch(
                "SELECT id FROM files WHERE user_id = ? AND parent_id = ? AND LOWER(filename) = LOWER(?) AND is_dir = 1",
                [$userId, $currentParentId, $folderName]
            );

            if ($existing) {
                $currentParentId = (int)$existing['id'];
            } else {
                // 创建文件夹
                $result = $this->createFolder($currentParentId, $folderName);
                if (!empty($result['success'])) {
                    $currentParentId = (int)$result['file_id'];
                }
                // 创建失败（如已存在但被并发创建）则重新查找
                else {
                    $existing = $this->db->fetch(
                        "SELECT id FROM files WHERE user_id = ? AND parent_id = ? AND LOWER(filename) = LOWER(?) AND is_dir = 1",
                        [$userId, $currentParentId, $folderName]
                    );
                    if ($existing) {
                        $currentParentId = (int)$existing['id'];
                    } else {
                        // 彻底无法创建，返回当前层级
                        break;
                    }
                }
            }
        }

        return $currentParentId;
    }


    /**
     * 递归删除目录及其内容。
     * @internal
     */
    public function removeDirRecursive($dir)
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function downloadFile($fileId)
    {
        $userId = $this->auth->getUserId();
        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);

        if (!$file) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        if ($file['is_dir']) {
            return $this->downloadFolder($file);
        }

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];

        if (!file_exists($fullPath)) {
            return ['success' => false, 'message' => '文件已被删除或不存在'];
        }

        $this->logOperation('download', $file['filename']);

        return ['success' => true, 'path' => $fullPath, 'filename' => $file['filename'], 'mime' => $file['mime_type'], 'size' => $file['filesize'], 'content_hash' => $file['content_hash'] ?? '', 'is_encrypted' => !empty($file['is_encrypted'])];
    }

    private function downloadFolder($file)
    {
        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];

        if (!is_dir($fullPath)) {
            return ['success' => false, 'message' => '文件夹不存在'];
        }

        $zipFile = UPLOAD_PATH . DIRECTORY_SEPARATOR . $file['filename'] . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.zip';

        if (file_exists($zipFile)) {
            return ['success' => false, 'message' => '临时文件创建失败，请稍后再试'];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE) !== true) {
            return ['success' => false, 'message' => '无法创建压缩文件'];
        }

        $this->addDirToZip($zip, $fullPath, $file['filename']);
        $zip->close();

        $this->logOperation('download_folder', $file['filename']);

        return ['success' => true, 'path' => $zipFile, 'filename' => $file['filename'] . '.zip', 'mime' => 'application/zip', 'size' => filesize($zipFile), 'temp' => true];
    }

    private function addDirToZip($zip, $dir, $prefix)
    {
        $zip->addEmptyDir($prefix);
        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            $safeItem = str_replace(['..', '\\', '/'], '', $item);
            if ($safeItem !== $item || strpos($item, '..') !== false) {
                continue;
            }

            $zipPath = $prefix . DIRECTORY_SEPARATOR . $safeItem;

            if (is_dir($path)) {
                $this->addDirToZip($zip, $path, $zipPath);
            } else {
                $zip->addFile($path, $zipPath);
            }
        }
    }

    public function deleteFile($fileId)
    {
        $userId = $this->auth->getUserId();
        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);

        if (!$file) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        if (!empty($file['is_locked'])) {
            return ['success' => false, 'message' => '文件已锁定，无法删除'];
        }

        // ── 阶段1：收集所有待删除项（事务外，不持锁） ──
        $itemsToDelete = [];
        if ($file['is_dir']) {
            try {
                $descendants = $this->db->fetchAll(
                    "WITH RECURSIVE tree(id, user_id, parent_id, filename, filepath, filesize, file_type, mime_type, is_dir, content_hash, depth) AS (
                        SELECT id, user_id, parent_id, filename, filepath, filesize, file_type, mime_type, is_dir, content_hash, 0
                        FROM files WHERE parent_id = ? AND user_id = ?
                        UNION ALL
                        SELECT f.id, f.user_id, f.parent_id, f.filename, f.filepath, f.filesize, f.file_type, f.mime_type, f.is_dir, f.content_hash, t.depth + 1
                        FROM files f INNER JOIN tree t ON f.parent_id = t.id WHERE f.user_id = ?
                    )
                    SELECT * FROM tree ORDER BY depth ASC",
                    [$fileId, $userId, $userId]
                );
            } catch (\Throwable $e) {
                // MySQL 5.7 等不支持 CTE：回退到 BFS
                $descendants = $this->fetchAllDescendantsFallback($fileId, $userId);
                // 补齐字段
                $descendants = array_map(function ($d) use ($userId) {
                    $row = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$d['id'], $userId]);
                    return $row ?: $d;
                }, $descendants);
            }
            $itemsToDelete = $descendants ?: [];
        }
        // 主文件也加入列表
        $itemsToDelete[] = $file;

        // ── 阶段2：执行所有文件 I/O（事务外，不持锁） ──
        $config = Config::getInstance();
        $now = time();
        $expireAt = $now + ($config->get('trash_retention_days') * 24 * 3600);

        $trashMoves = []; // 记录成功移动的文件，用于失败补偿

        foreach ($itemsToDelete as $item) {
            $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $item['filepath'];
            $trashBase = $item['id'] . '_' . basename($item['filepath']);
            if (strlen($trashBase) > 255) {
                $trashBase = substr($trashBase, 0, 255);
            }
            $trashPath = TRASH_PATH . DIRECTORY_SEPARATOR . $trashBase;

            $moved = false;
            if ($item['is_dir'] && is_dir($fullPath)) {
                $trashDir = dirname($trashPath);
                if (!is_dir($trashDir)) {
                    mkdir($trashDir, 0755, true);
                }
                $moved = @rename($fullPath, $trashPath);
            } elseif (file_exists($fullPath)) {
                $trashDir = dirname($trashPath);
                if (!is_dir($trashDir)) {
                    mkdir($trashDir, 0755, true);
                }
                $moved = @rename($fullPath, $trashPath);
            } else {
                $moved = true; // 文件已不在磁盘，仅删 DB 记录
            }

            if (!$moved) {
                // 补偿：回滚已移动的文件（逆序，子文件先移回）
                foreach (array_reverse($trashMoves) as $rollback) {
                    @rename($rollback['trash'], $rollback['original']);
                }
                return ['success' => false, 'message' => '移动文件到回收站失败'];
            }

            if (file_exists($trashPath)) {
                $trashMoves[] = ['original' => $fullPath, 'trash' => $trashPath];
            }
        }

        // ── 阶段3：短事务 — 仅 DB 写入（锁持有时间极短） ──
        try {
            ConcurrencyGuard::getInstance()->transactionImmediate(function () use ($itemsToDelete, $fileId, $userId, $now, $expireAt) {
                foreach ($itemsToDelete as $item) {
                    $this->db->insert('trash', [
                        'user_id'       => $item['user_id'],
                        'file_id'       => $item['id'],
                        'filename'      => $item['filename'],
                        'filepath'      => $item['filepath'],
                        'filesize'      => $item['filesize'],
                        'file_type'     => $item['file_type'],
                        'mime_type'     => $item['mime_type'],
                        'is_dir'        => $item['is_dir'],
                        'parent_id'     => $item['parent_id'],
                        'original_path' => $item['filepath'],
                        'deleted_at'    => $now,
                        'expire_at'     => $expireAt,
                    ]);
                }

                // 批量删除 files 记录（含主文件和所有子文件）
                $allIds = array_column($itemsToDelete, 'id');
                $allIds[] = $fileId;
                $allIds = array_unique($allIds);
                $placeholders = implode(',', array_fill(0, count($allIds), '?'));
                $this->db->query("DELETE FROM files WHERE id IN ({$placeholders})", $allIds);
            });
        } catch (\Throwable $e) {
            // DB 失败：补偿文件移动（把文件从回收站移回）
            foreach (array_reverse($trashMoves) as $rollback) {
                @rename($rollback['trash'], $rollback['original']);
            }
            return ['success' => false, 'message' => '删除失败：' . $e->getMessage()];
        }

        $this->logOperation('delete', $file['filename']);

        $this->db->clearCacheByTags(['parent:' . $file['parent_id']]);

        return ['success' => true, 'message' => '文件已移至回收站'];
    }



    public function renameFile($fileId, $newName)
    {
        $userId = $this->auth->getUserId();
        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);

        if (!$file) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        if (!empty($file['is_locked'])) {
            return ['success' => false, 'message' => '文件已锁定，无法重命名'];
        }

        $newName = Security::sanitizeFilename($newName);
        if (empty($newName)) {
            return ['success' => false, 'message' => '文件名不能为空'];
        }

        if ($newName === $file['filename']) {
            return ['success' => true, 'message' => '文件名未改变'];
        }

        $parent = $this->getFileById($file['parent_id']);
        $parentPath = $parent ? $parent['filepath'] : '';
        $newFilePath = $parentPath ? $parentPath . DIRECTORY_SEPARATOR . $newName : $newName;

        $oldFilePath = $file['filepath'];

        // ── 物理优先模式：先物理 rename，再 DB 事务 ──
        // 消除原"DB 先占位、物理后做"模式的窗口期（其他进程在此瞬间可能读到
        // "DB 显示已改名但物理文件仍在旧路径"的中间状态）。
        //
        // 物理 rename 失败时无 DB 变更，安全回滚。
        // DB 事务失败时物理 rename 已成功，需反向 rename 回旧路径补偿；
        // 补偿本身可能失败（罕见），此时记录 critical 告警供运维介入。
        $oldPath = FILES_PATH . DIRECTORY_SEPARATOR . $oldFilePath;
        $newPath = FILES_PATH . DIRECTORY_SEPARATOR . $newFilePath;

        // 预检查：旧文件存在，新文件不冲突（事务内会再查一次保证原子）
        if (!file_exists($oldPath)) {
            return ['success' => false, 'message' => '物理文件不存在，可能已被外部删除'];
        }
        if (file_exists($newPath)) {
            return ['success' => false, 'message' => '目标名称已被占用'];
        }

        if ($file['is_dir']) {
            // 目录 rename 需要先确保父目录存在
            $newDir = dirname($newPath);
            if (!is_dir($newDir)) {
                mkdir($newDir, 0755, true);
            }
        }

        error_clear_last();
        if (!@rename($oldPath, $newPath)) {
            $renameError = error_get_last();
            // rename() 跨文件系统会失败，回退到 copy+delete
            if (is_dir($oldPath)) {
                $copied = $this->copyDirRecursive($oldPath, $newPath);
                if ($copied) {
                    $this->rmdirRecursive($oldPath);
                } else {
                    if (is_dir($newPath)) $this->rmdirRecursive($newPath);
                    $detail = $renameError ? $renameError['message'] : '跨文件系统重命名失败';
                    return ['success' => false, 'message' => '重命名失败：' . $detail];
                }
            } else {
                $copied = @copy($oldPath, $newPath);
                if ($copied) {
                    @unlink($oldPath);
                } else {
                    @unlink($newPath);
                    $detail = $renameError ? $renameError['message'] : '跨文件系统重命名失败';
                    return ['success' => false, 'message' => '重命名失败：' . $detail];
                }
            }
        }

        // DB 事务：原子地冲突检查 + UPDATE 父记录 + 子代路径
        try {
            ConcurrencyGuard::getInstance()->transactionImmediate(function () use (
                $fileId, $userId, $newName, $newFilePath, $file
            ) {
                $existing = $this->db->fetch(
                    "SELECT id FROM files WHERE user_id = ? AND parent_id = ? AND LOWER(filename) = LOWER(?) AND id != ?",
                    [$userId, $file['parent_id'], $newName, $fileId]
                );
                if ($existing) {
                    throw new \RuntimeException('FILE_CONFLICT');
                }

                $this->db->update('files', [
                    'filename' => $newName,
                    'filepath' => $newFilePath,
                    'path_hash' => md5($newFilePath),
                    'updated_at' => time(),
                ], 'id = ? AND user_id = ?', [$fileId, $userId]);

                if ($file['is_dir']) {
                    $this->updateChildPaths($fileId, $file['filepath'], $newFilePath);
                }
            });
        } catch (\Throwable $e) {
            // DB 失败：物理回滚（rename 新路径回旧路径）
            $rollbackOk = @rename($newPath, $oldPath);
            if (!$rollbackOk) {
                // 补偿失败：物理文件停在新路径但 DB 仍指向旧路径，
                // 系统进入"孤儿文件"状态——记录 critical 告警供运维介入
                \App\Core\AsyncLogger::getInstance()->error(
                    'renameFile: compensation rename failed, orphan file created',
                    [
                        'file_id' => $fileId,
                        'old_path' => $oldFilePath,
                        'new_path' => $newFilePath,
                        'physical_at' => $newPath,
                        'db_error' => $e->getMessage(),
                    ]
                );
            }
            $msg = ($e instanceof \RuntimeException && str_contains($e->getMessage(), 'FILE_CONFLICT'))
                ? '同名文件已存在'
                : '重命名失败：' . $e->getMessage();
            return ['success' => false, 'message' => $msg];
        }

        $this->logOperation('rename', $file['filename'] . ' -> ' . $newName);

        $this->db->invalidateTableCache("files");
        $this->db->clearCacheByTags(['parent:' . $file['parent_id']]);

        return ['success' => true, 'message' => '重命名成功'];
    }

    /**
     * 批量更新目录下所有后代的 filepath / path_hash（DB only）。
     *
     * 物理文件由父目录的 rename() 一并移动，无需逐个物理重命名。
     * 使用递归 CTE 一次性查出所有后代，再逐行 UPDATE 更新路径。
     * 在事务内执行，SQLite WAL 模式下逐行 UPDATE 性能可接受。
     * $oldBase/$newBase 用于前缀替换。
     */
    private function updateChildPaths($parentId, $oldBase, $newBase)
    {
        $userId = $this->auth->getUserId();

        // 尝试使用 WITH RECURSIVE（MySQL 8.0+、PostgreSQL、SQLite 3.8.3+ 支持）
        try {
            $descendants = $this->db->fetchAll(
                "WITH RECURSIVE tree(id, filepath, depth) AS (
                    SELECT id, filepath, 0 FROM files WHERE parent_id = ? AND user_id = ?
                    UNION ALL
                    SELECT f.id, f.filepath, t.depth + 1 FROM files f
                    INNER JOIN tree t ON f.parent_id = t.id
                    WHERE f.user_id = ?
                )
                SELECT id, filepath FROM tree ORDER BY depth ASC",
                [$parentId, $userId, $userId]
            );
        } catch (\Throwable $e) {
            // MySQL 5.7 等不支持 CTE 的数据库：回退到循环查找所有后代
            $descendants = $this->fetchAllDescendantsFallback($parentId, $userId);
        }

        if (empty($descendants)) {
            return;
        }

        // 逐行 UPDATE：在事务内执行，SQLite WAL 模式下写不阻塞读，
        // 逐行 UPDATE 的性能可接受
        $now = time();
        foreach ($descendants as $child) {
            $oldChildPath = $child['filepath'];
            if (strpos($oldChildPath, $oldBase) === 0) {
                $newChildPath = $newBase . substr($oldChildPath, strlen($oldBase));
            } else {
                $newChildPath = $newBase . DIRECTORY_SEPARATOR . basename($oldChildPath);
            }

            $this->db->update('files', [
                'filepath' => $newChildPath,
                'path_hash' => md5($newChildPath),
                'updated_at' => $now,
            ], 'id = ? AND user_id = ?', [(int)$child['id'], $userId]);
        }
    }

    /**
     * updateChildPaths 的回退实现：逐层 BFS 查找所有后代。
     * 用于不支持 WITH RECURSIVE 的数据库（如 MySQL 5.7）。
     */
    private function fetchAllDescendantsFallback($parentId, $userId)
    {
        $all = [];
        $queue = [$parentId];
        $visited = [$parentId => true];
        while (!empty($queue)) {
            $currentId = array_shift($queue);
            $children = $this->db->fetchAll(
                "SELECT id, filepath FROM files WHERE parent_id = ? AND user_id = ?",
                [$currentId, $userId]
            ) ?: [];
            foreach ($children as $child) {
                if (isset($visited[$child['id']])) continue;
                $visited[$child['id']] = true;
                $all[] = $child;
                $queue[] = $child['id'];
            }
        }
        return $all;
    }

    public function moveFile($fileId, $targetParentId)
    {
        $userId = $this->auth->getUserId();
        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);

        if (!$file) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        if ($file['is_locked']) {
            return ['success' => false, 'message' => '文件已锁定，无法移动'];
        }

        if ($fileId == $targetParentId) {
            return ['success' => false, 'message' => '不能将文件夹移动到自身'];
        }

        if ($file['parent_id'] == $targetParentId) {
            return ['success' => false, 'message' => '文件已在目标目录中'];
        }

        $targetValid = $this->validateTargetDirectory($targetParentId);
        if (!$targetValid['success']) {
            return $targetValid;
        }

        if ($file['is_dir'] && $targetParentId > 0) {
            if ($this->isDescendantOf($targetParentId, $fileId, $userId)) {
                return ['success' => false, 'message' => '不能将文件夹移动到其子文件夹中'];
            }
        }

        $targetParent = $targetParentId > 0 ? $this->getFileById($targetParentId) : null;
        $targetParentPath = $targetParent ? $targetParent['filepath'] : '';
        $newFilePath = $targetParentPath ? $targetParentPath . DIRECTORY_SEPARATOR . $file['filename'] : $file['filename'];

        $oldFilePath = $file['filepath'];
        $oldFullPath = FILES_PATH . DIRECTORY_SEPARATOR . $oldFilePath;
        $newFullPath = FILES_PATH . DIRECTORY_SEPARATOR . $newFilePath;

        // ── 自愈：文件已在目标位置（DB 未更新），直接修复 DB ──
        if (!file_exists($oldFullPath) && file_exists($newFullPath)) {
            try {
                ConcurrencyGuard::getInstance()->transactionImmediate(function () use (
                    $fileId, $userId, $targetParentId, $newFilePath, $file
                ) {
                    $this->db->update('files', [
                        'parent_id' => $targetParentId,
                        'filepath' => $newFilePath,
                        'path_hash' => md5($newFilePath),
                        'updated_at' => time(),
                    ], 'id = ? AND user_id = ?', [$fileId, $userId]);

                    if ($file['is_dir']) {
                        $this->updateChildPaths($fileId, $file['filepath'], $newFilePath);
                    }
                });
                $this->db->invalidateTableCache("files");
                $this->db->clearCacheByTags(['parent:' . $file['parent_id'], 'parent:' . $targetParentId]);
                return ['success' => true, 'message' => '移动成功'];
            } catch (\Throwable $e) {
                return ['success' => false, 'message' => '自愈修复失败：' . $e->getMessage()];
            }
        }

        // ── 自愈：目标位置有残留文件（上次失败遗留），清理后继续 ──
        if (file_exists($newFullPath) && !file_exists($oldFullPath)) {
            // 新旧物理路径都有异常，无法自动处理
            return ['success' => false, 'message' => '目标位置已存在同名文件'];
        }

        if (!file_exists($oldFullPath)) {
            return ['success' => false, 'message' => '物理文件不存在，可能已被外部删除'];
        }

        if (file_exists($newFullPath)) {
            // 检查是否为上次失败遗留的孤立物理文件（DB 中无对应记录）
            $staleRecord = $this->db->fetch(
                "SELECT id FROM files WHERE user_id = ? AND filepath = ?",
                [$userId, $newFilePath]
            );
            if (!$staleRecord) {
                // 孤立物理文件，安全清理
                is_dir($newFullPath) ? $this->rmdirRecursive($newFullPath) : @unlink($newFullPath);
            } else {
                return ['success' => false, 'message' => '目标位置已存在同名文件'];
            }
        }

        $newDir = dirname($newFullPath);
        if (!is_dir($newDir)) {
            mkdir($newDir, 0755, true);
        }

        error_clear_last();
        if (!@rename($oldFullPath, $newFullPath)) {
            // rename() 跨文件系统会失败，回退到 copy+delete
            $renameError = error_get_last();
            if (is_dir($oldFullPath)) {
                $copied = $this->copyDirRecursive($oldFullPath, $newFullPath);
                if ($copied) {
                    $this->rmdirRecursive($oldFullPath);
                } else {
                    if (is_dir($newFullPath)) $this->rmdirRecursive($newFullPath);
                    $detail = $renameError ? $renameError['message'] : '跨文件系统移动失败';
                    return ['success' => false, 'message' => '移动失败：' . $detail];
                }
            } else {
                $copied = @copy($oldFullPath, $newFullPath);
                if ($copied) {
                    @unlink($oldFullPath);
                } else {
                    @unlink($newFullPath);
                    $detail = $renameError ? $renameError['message'] : '跨文件系统移动失败';
                    return ['success' => false, 'message' => '移动失败：' . $detail];
                }
            }
        }

        // DB 事务：原子地冲突检查 + UPDATE 父记录 + 子代路径
        try {
            ConcurrencyGuard::getInstance()->transactionImmediate(function () use (
                $fileId, $userId, $targetParentId, $newFilePath, $file
            ) {
                $existing = $this->db->fetch(
                    "SELECT id FROM files WHERE user_id = ? AND parent_id = ? AND LOWER(filename) = LOWER(?) AND id != ?",
                    [$userId, $targetParentId, $file['filename'], $fileId]
                );
                if ($existing) {
                    throw new \RuntimeException('FILE_CONFLICT');
                }

                $this->db->update('files', [
                    'parent_id' => $targetParentId,
                    'filepath' => $newFilePath,
                    'path_hash' => md5($newFilePath),
                    'updated_at' => time(),
                ], 'id = ? AND user_id = ?', [$fileId, $userId]);

                if ($file['is_dir']) {
                    $this->updateChildPaths($fileId, $file['filepath'], $newFilePath);
                }
            });
        } catch (\Throwable $e) {
            // DB 失败：物理回滚（rename 失败时回退到 copy+delete）
            $rollbackOk = @rename($newFullPath, $oldFullPath);
            if (!$rollbackOk && file_exists($newFullPath)) {
                if (is_dir($newFullPath)) {
                    $rollbackOk = $this->copyDirRecursive($newFullPath, $oldFullPath);
                    if ($rollbackOk) $this->rmdirRecursive($newFullPath);
                } else {
                    $rollbackOk = @copy($newFullPath, $oldFullPath);
                    if ($rollbackOk) @unlink($newFullPath);
                }
            }
            if (!$rollbackOk) {
                \App\Core\AsyncLogger::getInstance()->error(
                    'moveFile: physical rollback failed, file orphaned at new location',
                    [
                        'file_id' => $fileId,
                        'old_path' => $oldFilePath,
                        'new_path' => $newFilePath,
                        'target_parent' => $targetParentId,
                        'db_error' => $e->getMessage(),
                    ]
                );
            }
            $msg = ($e instanceof \RuntimeException && str_contains($e->getMessage(), 'FILE_CONFLICT'))
                ? '目标文件夹中已存在同名文件'
                : '移动失败：' . $e->getMessage();
            return ['success' => false, 'message' => $msg];
        }

        $this->logOperation('move', $file['filename']);
        $this->db->invalidateTableCache("files");
        $this->db->clearCacheByTags(['parent:' . $file['parent_id'], 'parent:' . $targetParentId]);

        return ['success' => true, 'message' => '移动成功'];
    }

    private function isDescendantOf($potentialDescendantId, $ancestorId, $userId)
    {
        // 尝试使用 WITH RECURSIVE（MySQL 8.0+、PostgreSQL、SQLite 3.8.3+ 支持）
        try {
            $result = $this->db->fetch(
                "WITH RECURSIVE ancestors(id, parent_id) AS (
                    SELECT id, parent_id FROM files WHERE id = ?
                    UNION ALL
                    SELECT f.id, f.parent_id FROM files f
                    INNER JOIN ancestors a ON f.id = a.parent_id
                    WHERE f.user_id = ?
                )
                SELECT 1 as found FROM ancestors WHERE parent_id = ? LIMIT 1",
                [$potentialDescendantId, $userId, $ancestorId]
            );
            return $result !== false;
        } catch (\Throwable $e) {
            // MySQL 5.7 等不支持 CTE 的数据库：回退到循环向上查找
            return $this->isDescendantOfFallback($potentialDescendantId, $ancestorId, $userId);
        }
    }

    /**
     * isDescendantOf 的回退实现：循环向上遍历 parent_id 链。
     * 用于不支持 WITH RECURSIVE 的数据库（如 MySQL 5.7）。
     */
    private function isDescendantOfFallback($potentialDescendantId, $ancestorId, $userId)
    {
        $currentId = $potentialDescendantId;
        $visited = [];
        while ($currentId > 0) {
            if ($currentId === (int)$ancestorId) {
                return true;
            }
            if (isset($visited[$currentId])) {
                break; // 防止循环引用
            }
            $visited[$currentId] = true;
            $row = $this->db->fetch(
                "SELECT parent_id FROM files WHERE id = ? AND user_id = ?",
                [$currentId, $userId]
            );
            if (!$row) break;
            $currentId = (int)$row['parent_id'];
        }
        return false;
    }

    public function copyFile($fileId, $targetParentId)
    {
        $userId = $this->auth->getUserId();
        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);

        if (!$file) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        if ($file['is_locked']) {
            return ['success' => false, 'message' => '文件已锁定，无法复制'];
        }

        if ($fileId == $targetParentId) {
            return ['success' => false, 'message' => '不能将文件夹复制到自身'];
        }

        $targetValid = $this->validateTargetDirectory($targetParentId);
        if (!$targetValid['success']) {
            return $targetValid;
        }

        if ($file['is_dir']) {
            if ($targetParentId > 0 && $this->isDescendantOf($targetParentId, $fileId, $userId)) {
                return ['success' => false, 'message' => '不能将文件夹复制到其子文件夹中'];
            }
            return $this->copyFolderRecursive($file, $targetParentId, $userId);
        }

        return $this->copySingleFile($file, $targetParentId, $userId);
    }

    private function copySingleFile($file, $targetParentId, $userId)
    {
        $storageCheck = $this->auth->checkStorageLimit($file['filesize']);
        if (!$storageCheck['status']) {
            return ['success' => false, 'message' => $storageCheck['message']];
        }

        $newFilename = $this->getUniqueFilename($userId, $targetParentId, $file['filename']);
        $newFilePath = $this->buildTargetPath($targetParentId, $newFilename);

        $oldFullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];
        $newFullPath = FILES_PATH . DIRECTORY_SEPARATOR . $newFilePath;

        if (!file_exists($oldFullPath)) {
            return ['success' => false, 'message' => '源文件不存在'];
        }

        $newDir = dirname($newFullPath);
        if (!is_dir($newDir)) {
            mkdir($newDir, 0755, true);
        }
        // 物理复制在事务外先执行
        if (!@copy($oldFullPath, $newFullPath)) {
            return ['success' => false, 'message' => '文件复制失败'];
        }

        // 事务内 INSERT；失败则删除物理文件，避免孤儿
        $now = time();
        try {
            ConcurrencyGuard::getInstance()->transactionImmediate(function () use (
                $userId, $newFilename, $newFilePath, $file, $targetParentId, $now
            ) {
                $this->db->insert('files', [
                    'user_id' => $userId,
                    'filename' => $newFilename,
                    'filepath' => $newFilePath,
                    'filesize' => $file['filesize'],
                    'file_type' => $file['file_type'],
                    'mime_type' => $file['mime_type'],
                    'is_dir' => 0,
                    'parent_id' => $targetParentId,
                    'path_hash' => md5($newFilePath),
                    'content_hash' => $file['content_hash'] ?? '',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        } catch (\Throwable $e) {
            if (file_exists($newFullPath)) {
                @unlink($newFullPath);
            }
            return ['success' => false, 'message' => '复制失败：' . $e->getMessage()];
        }

        $this->logOperation('copy', $file['filename']);
        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => '复制成功'];
    }

    private function copyFolderRecursive($folder, $targetParentId, $userId)
    {
        $totalFiles = $this->countFilesInFolder($folder['id'], $userId);
        $totalSize = $this->calculateFolderSize($folder['id'], $userId) + $folder['filesize'];

        $storageCheck = $this->auth->checkStorageLimit($totalSize);
        if (!$storageCheck['status']) {
            return ['success' => false, 'message' => $storageCheck['message']];
        }

        $newFolderName = $this->getUniqueFilename($userId, $targetParentId, $folder['filename']);
        $newFolderPath = $this->buildTargetPath($targetParentId, $newFolderName);
        $newFullPath = FILES_PATH . DIRECTORY_SEPARATOR . $newFolderPath;

        if (!mkdir($newFullPath, 0755, true) && !is_dir($newFullPath)) {
            return ['success' => false, 'message' => '创建目标文件夹失败'];
        }

        // ── 阶段1：收集所有后代信息（事务外，不持锁） ──
        try {
            $descendants = $this->db->fetchAll(
                "WITH RECURSIVE tree(id, parent_id, filename, filepath, filesize, file_type, mime_type, is_dir, content_hash, depth) AS (
                    SELECT id, parent_id, filename, filepath, filesize, file_type, mime_type, is_dir, content_hash, 0
                    FROM files WHERE parent_id = ? AND user_id = ?
                    UNION ALL
                    SELECT f.id, f.parent_id, f.filename, f.filepath, f.filesize, f.file_type, f.mime_type, f.is_dir, f.content_hash, t.depth + 1
                    FROM files f INNER JOIN tree t ON f.parent_id = t.id WHERE f.user_id = ?
                )
                SELECT id, parent_id, filename, filepath, filesize, file_type, mime_type, is_dir, content_hash FROM tree
                ORDER BY depth ASC",
                [$folder['id'], $userId, $userId]
            );
        } catch (\Throwable $e) {
            // MySQL 5.7 等不支持 CTE：回退到 BFS
            $fallbackIds = $this->fetchAllDescendantsFallback($folder['id'], $userId);
            $descendants = [];
            foreach ($fallbackIds as $d) {
                $row = $this->db->fetch("SELECT id, parent_id, filename, filepath, filesize, file_type, mime_type, is_dir, content_hash FROM files WHERE id = ? AND user_id = ?", [$d['id'], $userId]);
                if ($row) $descendants[] = $row;
            }
        }

        // ── 阶段2：执行所有文件 I/O（事务外，不持锁） ──
        // 用源目录结构推导目标路径：将源文件夹的 filepath 前缀替换为新的根目录路径
        $sourcePrefix = $folder['filepath']; // 源文件夹的 filepath（如 "docs"）
        $createdFiles = []; // 补偿用
        $createdFiles[] = ['type' => 'dir', 'path' => $newFullPath];
        $dbInserts = []; // 收集待插入数据

        try {
            foreach ($descendants as $child) {
                // 基于源路径推导目标路径：替换前缀
                $relativePath = substr($child['filepath'], strlen($sourcePrefix) + 1); // 去掉源根前缀
                $childTargetPath = $newFolderPath . DIRECTORY_SEPARATOR . $relativePath;
                $childFullPath = FILES_PATH . DIRECTORY_SEPARATOR . $childTargetPath;

                if ($child['is_dir']) {
                    if (!mkdir($childFullPath, 0755, true) && !is_dir($childFullPath)) {
                        throw new \RuntimeException('创建目标子文件夹失败');
                    }
                    $createdFiles[] = ['type' => 'dir', 'path' => $childFullPath];

                    $dbInserts[] = [
                        'user_id' => $userId,
                        'filename' => $child['filename'],
                        'filepath' => $childTargetPath,
                        'filesize' => 0,
                        'file_type' => 'folder',
                        'mime_type' => '',
                        'is_dir' => 1,
                        'source_parent_id' => $child['parent_id'],
                        'path_hash' => md5($childTargetPath),
                        'content_hash' => '',
                        'source_id' => $child['id'],
                    ];
                } else {
                    $oldFullPath = FILES_PATH . DIRECTORY_SEPARATOR . $child['filepath'];
                    if (!file_exists($oldFullPath)) {
                        continue;
                    }

                    $newDir = dirname($childFullPath);
                    if (!is_dir($newDir)) {
                        mkdir($newDir, 0755, true);
                    }
                    if (!@copy($oldFullPath, $childFullPath)) {
                        throw new \RuntimeException('复制文件失败：' . $child['filename']);
                    }
                    $createdFiles[] = ['type' => 'file', 'path' => $childFullPath];

                    $dbInserts[] = [
                        'user_id' => $userId,
                        'filename' => $child['filename'],
                        'filepath' => $childTargetPath,
                        'filesize' => $child['filesize'],
                        'file_type' => $child['file_type'],
                        'mime_type' => $child['mime_type'],
                        'is_dir' => 0,
                        'source_parent_id' => $child['parent_id'],
                        'path_hash' => md5($childTargetPath),
                        'content_hash' => $child['content_hash'] ?? '',
                        'source_id' => $child['id'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            $this->rollbackCreatedFiles($createdFiles);
            return ['success' => false, 'message' => $e->getMessage()];
        }

        // ── 阶段3：短事务 — 批量 INSERT（锁持有时间极短） ──
        $fileCount = 0;
        $copiedSize = 0;
        try {
            ConcurrencyGuard::getInstance()->transactionImmediate(function () use (
                $userId, $targetParentId, $newFolderName, $newFolderPath,
                $folder, $dbInserts, &$fileCount, &$copiedSize
            ) {
                $now = time();
                // 先插入根目录，拿到 newFolderId
                $newFolderId = $this->db->insert('files', [
                    'user_id' => $userId,
                    'filename' => $newFolderName,
                    'filepath' => $newFolderPath,
                    'filesize' => 0,
                    'file_type' => 'folder',
                    'mime_type' => '',
                    'is_dir' => 1,
                    'parent_id' => $targetParentId,
                    'path_hash' => md5($newFolderPath),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // 构建 源ID → 新ID 映射
                $idMap = [$folder['id'] => (int)$newFolderId];

                // 批量插入所有子项
                foreach ($dbInserts as $insert) {
                    $insert['parent_id'] = $idMap[$insert['source_parent_id']] ?? (int)$newFolderId;
                    unset($insert['source_parent_id']);
                    $sourceId = $insert['source_id'];
                    unset($insert['source_id']);
                    $insert['created_at'] = $now;
                    $insert['updated_at'] = $now;
                    $newId = $this->db->insert('files', $insert);
                    $idMap[$sourceId] = (int)$newId;
                    $fileCount++;
                    $copiedSize += $insert['filesize'] ?? 0;
                }
            });
        } catch (\Throwable $e) {
            // DB 失败：补偿删除已创建的物理文件
            $this->rollbackCreatedFiles($createdFiles);
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $this->logOperation('copy', $folder['filename'] . '（含' . $fileCount . '个子项）');
        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => '文件夹复制成功（' . $fileCount . '个子项）'];
    }

    /**
     * 补偿清理：删除已创建的文件/目录（从最深路径开始，保证子项先删）。
     * 用于 copyFolderRecursive 阶段2/3失败时回滚物理文件。
     */
    private function rollbackCreatedFiles(array $createdFiles): void
    {
        foreach (array_reverse($createdFiles) as $item) {
            if ($item['type'] === 'dir') {
                @rmdir($item['path']);
            } else {
                @unlink($item['path']);
            }
        }
    }

    private function copyDirRecursive(string $src, string $dst): bool
    {
        if (!is_dir($src)) return false;
        if (!mkdir($dst, 0755, true) && !is_dir($dst)) return false;

        $items = @scandir($src);
        if ($items === false) return false;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $srcPath = $src . DIRECTORY_SEPARATOR . $item;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $item;
            if (is_dir($srcPath)) {
                if (!$this->copyDirRecursive($srcPath, $dstPath)) return false;
            } else {
                if (!@copy($srcPath, $dstPath)) return false;
            }
        }
        return true;
    }

    private function rmdirRecursive(string $dir): bool
    {
        if (!is_dir($dir)) return false;
        $items = @scandir($dir);
        if ($items === false) return false;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : @unlink($path);
        }
        return @rmdir($dir);
    }

    private function buildTargetPath($targetParentId, $filename)
    {
        if ($targetParentId <= 0) {
            return $filename;
        }
        $targetParent = $this->getFileById($targetParentId);
        $targetParentPath = $targetParent ? $targetParent['filepath'] : '';
        return $targetParentPath ? $targetParentPath . DIRECTORY_SEPARATOR . $filename : $filename;
    }

    private function countFilesInFolder($folderId, $userId)
    {
        // 尝试使用递归 CTE 一次性统计，避免 N+1 查询
        try {
            $result = $this->db->fetch(
                "WITH RECURSIVE tree(id) AS (
                    SELECT id FROM files WHERE parent_id = ? AND user_id = ?
                    UNION ALL
                    SELECT f.id FROM files f INNER JOIN tree t ON f.parent_id = t.id
                    WHERE f.user_id = ?
                )
                SELECT COUNT(*) as cnt FROM tree",
                [$folderId, $userId, $userId]
            );
            return $result ? (int)$result['cnt'] : 0;
        } catch (\Throwable $e) {
            // MySQL 5.7 等不支持 CTE：回退到 BFS
            $descendants = $this->fetchAllDescendantsFallback($folderId, $userId);
            return count($descendants);
        }
    }

    public function calculateFolderSize($folderId, $userId)
    {
        // 尝试使用 WITH RECURSIVE 一次性计算
        try {
            $result = $this->db->fetch(
                "WITH RECURSIVE tree(id) AS (
                    SELECT ?
                    UNION ALL
                    SELECT f.id FROM files f INNER JOIN tree t ON f.parent_id = t.id
                    WHERE f.user_id = ?
                )
                SELECT COALESCE(SUM(f.filesize), 0) as total
                FROM files f INNER JOIN tree t ON f.id = t.id
                WHERE f.is_dir = 0",
                [$folderId, $userId]
            );
            return $result ? (int)$result['total'] : 0;
        } catch (\Throwable $e) {
            // MySQL 5.7 等不支持 CTE：回退到 BFS
            $descendants = $this->fetchAllDescendantsFallback($folderId, $userId);
            $total = 0;
            foreach ($descendants as $d) {
                $row = $this->db->fetch("SELECT filesize, is_dir FROM files WHERE id = ? AND user_id = ?", [$d['id'], $userId]);
                if ($row && !$row['is_dir']) {
                    $total += (int)$row['filesize'];
                }
            }
            return $total;
        }
    }

    public function batchCopyItems($fileIds, $targetParentId)
    {
        $userId = $this->auth->getUserId();
        $targetValid = $this->validateTargetDirectory($targetParentId);
        if (!$targetValid['success']) {
            return ['success' => false, 'message' => $targetValid['message']];
        }

        $successCount = 0;
        $failCount = 0;
        $errors = [];
        $totalCopied = 0;

        foreach ($fileIds as $fileId) {
            $result = $this->copyFile(intval($fileId), $targetParentId);
            if ($result['success']) {
                $successCount++;
                $totalCopied += isset($result['file_count']) ? $result['file_count'] + 1 : 1;
            } else {
                $failCount++;
                $errors[] = $result['message'];
            }
        }

        return [
            'success' => $successCount > 0,
            'message' => $failCount === 0
                ? "批量复制完成：{$successCount} 项成功（共{$totalCopied}个文件）"
                : "批量复制完成：{$successCount} 项成功，{$failCount} 项失败",
            'succeeded' => $successCount,
            'failed' => $failCount,
            'total_files' => $totalCopied,
            'errors' => array_slice($errors, 0, 10),
        ];
    }

    public function batchMoveItems($fileIds, $targetParentId)
    {
        $userId = $this->auth->getUserId();
        $targetValid = $this->validateTargetDirectory($targetParentId);
        if (!$targetValid['success']) {
            return ['success' => false, 'message' => $targetValid['message']];
        }

        $successCount = 0;
        $failCount = 0;
        $skipCount = 0;
        $errors = [];

        // 去重：如果批量中包含文件夹及其子文件，跳过子文件（文件夹移动会一并移动子文件）
        $folderIds = [];
        $idSet = array_map('intval', $fileIds);
        foreach ($idSet as $fid) {
            $row = $this->db->fetch("SELECT is_dir FROM files WHERE id = ? AND user_id = ?", [$fid, $userId]);
            if ($row && $row['is_dir']) {
                $folderIds[] = $fid;
            }
        }
        if (!empty($folderIds)) {
            $placeholders = implode(',', array_fill(0, count($folderIds), '?'));
            $childIds = $this->db->fetchAll(
                "SELECT id FROM files WHERE user_id = ? AND parent_id IN ({$placeholders})",
                array_merge([$userId], $folderIds)
            ) ?: [];
            // 递归查找所有后代
            if (!empty($childIds)) {
                try {
                    $descendantIds = $this->db->fetchAll(
                        "WITH RECURSIVE tree(id) AS (
                            SELECT id FROM files WHERE user_id = ? AND parent_id IN ({$placeholders})
                            UNION ALL
                            SELECT f.id FROM files f INNER JOIN tree t ON f.parent_id = t.id WHERE f.user_id = ?
                        )
                        SELECT id FROM tree",
                        array_merge([$userId], $folderIds, [$userId])
                    );
                } catch (\Throwable $e) {
                    // MySQL 5.7 等不支持 CTE：逐个文件夹 BFS 查找后代
                    $descendantIds = [];
                    foreach ($folderIds as $fid) {
                        $desc = $this->fetchAllDescendantsFallback($fid, $userId);
                        $descendantIds = array_merge($descendantIds, $desc);
                    }
                }
                $descendantSet = array_map('intval', array_column($descendantIds, 'id'));
                $idSet = array_values(array_diff($idSet, $descendantSet));
                $skipCount = count($fileIds) - count($idSet);
            }
        }

        foreach ($idSet as $fileId) {
            $result = $this->moveFile(intval($fileId), $targetParentId);
            if ($result['success']) {
                $successCount++;
            } else {
                $failCount++;
                $errors[] = $result['message'];
            }
        }

        $msgParts = [];
        if ($successCount > 0) $msgParts[] = "{$successCount} 项成功";
        if ($failCount > 0) $msgParts[] = "{$failCount} 项失败";
        if ($skipCount > 0) $msgParts[] = "{$skipCount} 项随父文件夹移动";
        $message = '批量移动完成：' . implode('，', $msgParts);

        return [
            'success' => $successCount > 0,
            'message' => $message,
            'succeeded' => $successCount,
            'failed' => $failCount,
            'skipped' => $skipCount,
            'errors' => array_slice($errors, 0, 10),
        ];
    }

    public function validateTargetDirectory($targetParentId)
    {
        if ($targetParentId <= 0) {
            return ['success' => true];
        }
        $userId = $this->auth->getUserId();
        $target = $this->db->fetch(
            "SELECT id, is_dir FROM files WHERE id = ? AND user_id = ?",
            [$targetParentId, $userId]
        );
        if (!$target) {
            return ['success' => false, 'message' => '目标文件夹不存在'];
        }
        if (!$target['is_dir']) {
            return ['success' => false, 'message' => '目标不是有效的文件夹'];
        }
        return ['success' => true];
    }

    /** @deprecated Use FileFavoriteService instead */
    public function toggleFavorite($fileId)
    {
        return (new FileFavoriteService())->toggleFavorite($fileId);
    }

    /** @deprecated Use FileFavoriteService instead */
    public function getFavorites($page = 1, $pageSize = 50)
    {
        return (new FileFavoriteService())->getFavorites($page, $pageSize);
    }

    /** @deprecated Use FileFavoriteService instead */
    public function getFavoritesCount()
    {
        return (new FileFavoriteService())->getFavoritesCount();
    }

    /** @deprecated Use FileQueryService instead */
    public function searchFiles($keyword, $type = 'all', $page = 1, $pageSize = 50, $sortBy = 'name', $sortOrder = 'asc')
    {
        return (new FileQueryService())->searchFiles($keyword, $type, $page, $pageSize, $sortBy, $sortOrder);
    }

    /** @deprecated Use FileQueryService instead */
    public function getSearchCount($keyword, $type = 'all')
    {
        return (new FileQueryService())->getSearchCount($keyword, $type);
    }

    /** @deprecated Use FileQueryService instead */
    public function getFileById($fileId)
    {
        return (new FileQueryService())->getFileById($fileId);
    }

    /** @deprecated Use FileQueryService instead */
    public function getBreadcrumb($parentId)
    {
        return (new FileQueryService())->getBreadcrumb($parentId);
    }

    /** @deprecated Use FileQueryService instead */
    public function getAllFoldersTree()
    {
        return (new FileQueryService())->getAllFoldersTree();
    }

    /** @deprecated Use FileQueryService instead */
    public function getStorageInfo()
    {
        return (new FileQueryService())->getStorageInfo();
    }

    /** @deprecated Use FileQueryService instead */
    public function getFileStats()
    {
        return (new FileQueryService())->getFileStats();
    }

    public function updateTags($fileId, $tags)
    {
        $userId = $this->auth->getUserId();
        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);

        if (!$file) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        $serializedTags = $this->serializeTags($tags);

        $this->db->update('files', [
            'tags' => $serializedTags,
            'updated_at' => time(),
        ], 'id = ? AND user_id = ?', [$fileId, $userId]);

        $this->logOperation('update_tags', $file['filename'] . ' [' . $serializedTags . ']');

        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => '标签已更新', 'tags' => $this->parseTags($serializedTags)];
    }

    public function updateDescription($fileId, $description)
    {
        $userId = $this->auth->getUserId();
        $this->db->update('files', ['description' => $description, 'updated_at' => time()], 'id = ? AND user_id = ?', [$fileId, $userId]);
        return ['success' => true, 'message' => '描述已更新'];
    }

    /** @deprecated Use FileEncryptionService instead */
    public function encryptFile($fileId)
    {
        return (new FileEncryptionService())->encryptFile($fileId);
    }

    /** @deprecated Use FileEncryptionService instead */
    public function decryptFile($fileId)
    {
        return (new FileEncryptionService())->decryptFile($fileId);
    }

    /** @deprecated Use FileEncryptionService instead */
    public function decryptFileToTemp($fileId)
    {
        return (new FileEncryptionService())->decryptFileToTemp($fileId);
    }

    /** @deprecated Use FileAccessService instead */
    public function recordAccess($fileId)
    {
        return (new FileAccessService())->recordAccess($fileId);
    }

    /** @deprecated Use FileAccessService instead */
    public function getRecentAccess()
    {
        return (new FileAccessService())->getRecentAccess();
    }

    /**
     * 直接删除文件（物理删除 + DB 删除，不走回收站）。
     * 上传冲突覆盖、内部清理时使用。
     * @internal
     */
    public function deleteFileById($fileId, $userId)
    {
        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);
        if (!$file) return;

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];
        if (file_exists($fullPath) && !$file['is_dir']) {
            @unlink($fullPath);
        }

        $this->db->delete('files', 'id = ? AND user_id = ?', [$fileId, $userId]);

        if (!$file['is_dir'] && $file['filesize'] > 0) {
            // storage_used 实时聚合，无需维护
        }
    }

    /**
     * 在指定目录下生成不冲突的唯一文件名。
     * @internal
     */
    public function getUniqueFilename($userId, $parentId, $filename)
    {
        // SQLite 无行级锁，需 flock 串行化文件名唯一性检查；
        // MySQL/PostgreSQL 已有 UNIQUE INDEX idx_files_user_parent_filename，
        // 配合行级锁即可保证一致性，跳过文件锁减少跨进程争用。
        $dbType = $this->db->getDbType();
        $useFileLock = ($dbType === 'sqlite');

        $lockKey = 'filename_lock_' . $userId . '_' . $parentId;
        $lockFile = DATA_PATH . DIRECTORY_SEPARATOR . md5($lockKey) . '.lock';
        $lockFp = $useFileLock ? fopen($lockFile, 'c+') : null;

        if (!$useFileLock || ($lockFp && flock($lockFp, LOCK_EX))) {
            try {
                $base = pathinfo($filename, PATHINFO_FILENAME);
                $ext = pathinfo($filename, PATHINFO_EXTENSION);

                // ── 批量查询所有冲突文件名，减少数据库往返 ──
                // 使用 LOWER() 保证跨数据库大小写一致性：
                // PostgreSQL 的 LIKE 和 = 默认大小写敏感，而 MySQL（默认排序规则）
                // 和 SQLite 的 LIKE 对 ASCII 不敏感。LOWER() 统一为不敏感比较，
                // 与 Windows/macOS 文件系统行为一致，避免 "Test.txt" 与 "test.txt" 冲突漏检。
                $pattern = $ext ? $base . ' (%).' . $ext : $base . ' (%)';
                $existingFiles = $this->db->fetchAll(
                    "SELECT filename FROM files WHERE user_id = ? AND parent_id = ? AND (LOWER(filename) = LOWER(?) OR LOWER(filename) LIKE LOWER(?))",
                    [$userId, $parentId, $filename, $pattern]
                ) ?: [];
                $existingNames = array_column($existingFiles, 'filename');
                $existingNamesLower = array_map('strtolower', $existingNames);

                if (!in_array(strtolower($filename), $existingNamesLower)) {
                    if ($lockFp) {
                        flock($lockFp, LOCK_UN);
                        fclose($lockFp);
                        @unlink($lockFile);
                    }
                    return $filename;
                }

                $counter = 1;
                $newFilename = $filename;

                while (in_array(strtolower($newFilename), $existingNamesLower)) {
                    $newFilename = $ext ? "{$base} ({$counter}).{$ext}" : "{$base} ({$counter})";
                    $counter++;
                }

                if ($lockFp) {
                    flock($lockFp, LOCK_UN);
                    fclose($lockFp);
                    @unlink($lockFile);
                }

                return $newFilename;
            } catch (\Exception $e) {
                if ($lockFp) {
                    flock($lockFp, LOCK_UN);
                    fclose($lockFp);
                    @unlink($lockFile);
                }
                throw $e;
            }
        }

        // 兜底降级（仅 SQLite 文件锁失败时走到这里）
        return $filename . '_' . time() . '_' . bin2hex(random_bytes(4));
    }

    private function parseTags($tagsStr)
    {
        if (empty($tagsStr)) return [];
        return array_values(array_filter(array_map('trim', explode(',', $tagsStr))));
    }

    private function serializeTags($tags)
    {
        if (empty($tags) || !is_array($tags)) return '';
        return implode(',', array_values(array_filter(array_map('trim', $tags))));
    }
}
