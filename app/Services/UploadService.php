<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Core\Config;
use App\Core\ConcurrencyGuard;
use App\Support\LogHelper;
use App\Support\FileTypeTrait;

/**
 * 文件上传服务。
 *
 * 从 FileManagerService 拆分而来，承担：
 *   - 普通文件上传（含冲突处理）
 *   - 分片上传（断点续传）
 *   - 上传任务管理（取消、清理过期任务、待传大小跟踪）
 *
 * 通过持有 FileManagerService 引用复用其 getFileById / deleteFileById /
 * getUniqueFilename / removeDirRecursive，避免代码重复。
 */
class UploadService
{
    use LogHelper;
    use FileTypeTrait;

    private $db;
    private $auth;
    private $config;
    private $fm;

    public function __construct(FileManagerService $fm)
    {
        $this->fm = $fm;
        $this->db = Database::getInstance();
        $this->auth = new AuthService();
        $this->config = Config::getInstance();
    }

    public function getPendingUploadSize($userId)
    {
        $pendingFile = DATA_PATH . DIRECTORY_SEPARATOR . 'pending_upload_' . $userId . '.json';
        if (!file_exists($pendingFile)) {
            return 0;
        }

        $fp = fopen($pendingFile, 'c+');
        if (!$fp) {
            return 0;
        }

        if (flock($fp, LOCK_SH)) {
            $content = stream_get_contents($fp);
            flock($fp, LOCK_UN);
            fclose($fp);

            if ($content) {
                $data = json_decode($content, true);
                if (is_array($data) && isset($data['size'])) {
                    $cleanupTime = $data['updated_at'] ?? 0;
                    if (time() - $cleanupTime > 3600) {
                        unlink($pendingFile);
                        return 0;
                    }
                    return $data['size'];
                }
            }
            return 0;
        }

        fclose($fp);
        return 0;
    }

    public function addPendingUpload($userId, $size)
    {
        $pendingFile = DATA_PATH . DIRECTORY_SEPARATOR . 'pending_upload_' . $userId . '.json';
        $fp = fopen($pendingFile, 'c+');
        if (!$fp) {
            return;
        }

        if (flock($fp, LOCK_EX)) {
            $data = ['size' => 0, 'updated_at' => time()];
            $content = stream_get_contents($fp);
            if ($content) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }

            $data['size'] += $size;
            $data['updated_at'] = time();

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            fflush($fp);

            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    public function removePendingUpload($userId, $size)
    {
        $pendingFile = DATA_PATH . DIRECTORY_SEPARATOR . 'pending_upload_' . $userId . '.json';
        if (!file_exists($pendingFile)) {
            return;
        }

        $fp = fopen($pendingFile, 'c+');
        if (!$fp) {
            return;
        }

        if (flock($fp, LOCK_EX)) {
            $content = stream_get_contents($fp);
            if ($content) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $data['size'] = max(0, ($data['size'] ?? 0) - $size);
                    $data['updated_at'] = time();

                    ftruncate($fp, 0);
                    rewind($fp);
                    fwrite($fp, json_encode($data));
                    fflush($fp);
                }
            }

            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    public function cleanupExpiredUploadTasks()
    {
        $expiredTime = time() - 86400;
        $cleanedCount = 0;

        // ── 清理前先查询 upload_tasks 表，获取所有活跃的 upload_id 集合 ──
        // MySQL/PostgreSQL 模式下进度存 DB 而非 .json，需据此避免误清活跃分片目录
        $activeUploadIds = [];
        $allTasks = $this->db->fetchAll("SELECT upload_id, updated_at FROM upload_tasks");
        foreach ($allTasks as $t) {
            $activeUploadIds[$t['upload_id']] = $t['updated_at'];
        }

        // ── 清理文件记录的分片进度（SQLite .json 模式，仍按 created_at 判断过期） ──
        $progressFiles = glob(UPLOAD_PATH . DIRECTORY_SEPARATOR . '*.json');
        $knownUploadIds = [];
        foreach ($progressFiles as $progressFile) {
            $content = file_get_contents($progressFile);
            $task = json_decode($content, true);
            $uploadId = $task['upload_id'] ?? basename($progressFile, '.json');
            $knownUploadIds[$uploadId] = true;
            if ($task && isset($task['created_at']) && $task['created_at'] < $expiredTime) {
                $chunkDir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId;
                $this->cleanChunkDir($chunkDir);
                @unlink($progressFile);
                $lockFile = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId . '.lock';
                @unlink($lockFile);
                $cleanedCount++;
            }
        }

        // ── 清理孤立目录：仅当“无 DB 记录 且 无 .json 进度文件”时才视为孤立 ──
        $chunkDirs = glob(UPLOAD_PATH . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        foreach ($chunkDirs as $dir) {
            $dirName = basename($dir);
            // 有 DB 记录 或 有 .json 进度文件 → 不视为孤立
            if (isset($activeUploadIds[$dirName])) continue;
            if (isset($knownUploadIds[$dirName])) continue;
            // 真正孤立：清理
            $this->cleanChunkDir($dir);
            $cleanedCount++;
        }

        // ── 清理孤立 .lock 文件：无 DB 记录 且 无对应 .json 的锁文件 ──
        $lockFiles = glob(UPLOAD_PATH . DIRECTORY_SEPARATOR . '*.lock');
        foreach ($lockFiles as $lockFile) {
            $uploadId = basename($lockFile, '.lock');
            if (isset($activeUploadIds[$uploadId])) continue;
            if (!isset($knownUploadIds[$uploadId])) {
                @unlink($lockFile);
                $cleanedCount++;
            }
        }

        // ── 清理过期的 DB 记录（基于 updated_at 判断过期） ──
        $expiredTasks = $this->db->fetchAll("SELECT * FROM upload_tasks WHERE updated_at < ?", [$expiredTime]);
        foreach ($expiredTasks as $task) {
            $chunkDir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $task['upload_id'];
            $this->cleanChunkDir($chunkDir);
            $this->db->delete('upload_tasks', 'id = ?', [$task['id']]);
            $cleanedCount++;
        }

        $this->db->invalidateTableCache("files");

        return $cleanedCount;
    }

    public function uploadFile($parentId, $fileInfo, $chunkInfo = null, $conflictResolution = null)
    {
        ignore_user_abort(true);
        $userId = $this->auth->getUserId();

        if (!isset($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
            return ['success' => false, 'message' => '无效的上传文件'];
        }

        $filename = Security::sanitizeFilename($fileInfo['name']);

        if (!Security::validateFileExtension($filename)) {
            return ['success' => false, 'message' => '不允许上传此类型的文件'];
        }

        $fileSize = $fileInfo['size'];

        if ($fileSize > $this->config->get('max_upload_size')) {
            return ['success' => false, 'message' => '文件大小超过限制（最大' . Security::formatSize($this->config->get('max_upload_size')) . '）'];
        }

        $storageCheck = $this->auth->checkStorageLimit($fileSize);
        if (!$storageCheck['status']) {
            return ['success' => false, 'message' => $storageCheck['message']];
        }

        if (!Security::validateFileContent($fileInfo['tmp_name'], $filename)) {
            return ['success' => false, 'message' => '文件内容安全检查失败'];
        }

        $parent = $this->fm->getFileById($parentId);
        $parentPath = $parent ? $parent['filepath'] : '';

        // 大文件（>100MB）跳过全量哈希计算以节省 I/O，放弃该环节的去重
        $contentHash = $fileSize > 104857600 ? '' : $this->calculateSHA256($fileInfo['tmp_name']);

        $duplicate = $this->db->fetch(
            "SELECT id, filename, filesize, filepath FROM files WHERE user_id = ? AND parent_id = ? AND content_hash = ? AND content_hash != ''",
            [$userId, $parentId, $contentHash]
        );

        if ($duplicate) {
            if ($conflictResolution === 'overwrite') {
                $this->fm->deleteFileById($duplicate['id'], $userId);
            } elseif ($conflictResolution === 'keep_both') {
                $filename = $this->fm->getUniqueFilename($userId, $parentId, $filename);
            } elseif ($conflictResolution === 'cancel') {
                return [
                    'success' => false,
                    'message' => '已取消上传',
                ];
            } else {
                return [
                    'success' => false,
                    'duplicate_conflict' => true,
                    'message' => '当前文件夹已存在相同内容的文件："' . $duplicate['filename'] . '"',
                    'duplicate_filename' => $duplicate['filename'],
                    'duplicate_id' => $duplicate['id'],
                ];
            }
        } else {
            $sameNameFile = $this->db->fetch(
                "SELECT id, filename FROM files WHERE user_id = ? AND parent_id = ? AND filename = ?",
                [$userId, $parentId, $filename]
            );
            if ($sameNameFile) {
                if ($conflictResolution === 'overwrite') {
                    $this->fm->deleteFileById($sameNameFile['id'], $userId);
                } elseif ($conflictResolution === 'keep_both') {
                    $filename = $this->fm->getUniqueFilename($userId, $parentId, $filename);
                } elseif ($conflictResolution === 'cancel') {
                    return [
                        'success' => false,
                        'message' => '已取消上传',
                    ];
                } else {
                    return [
                        'success' => false,
                        'duplicate_conflict' => true,
                        'message' => '当前文件夹已存在同名文件："' . $sameNameFile['filename'] . '"',
                        'duplicate_filename' => $sameNameFile['filename'],
                        'duplicate_id' => $sameNameFile['id'],
                    ];
                }
            }
        }

        $filePath = $parentPath ? $parentPath . DIRECTORY_SEPARATOR . $filename : $filename;
        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $filePath;

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // ── 文件I/O移出事务：先保存文件，再开事务写入数据库 ──
        if (!move_uploaded_file($fileInfo['tmp_name'], $fullPath)) {
            return ['success' => false, 'message' => '文件保存失败'];
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeType = $this->getMimeType($fullPath);
        $now = time();

        // ── 事务仅包裹最核心的 INSERT，缩短锁持有时间 ──
        $this->db->beginTransaction();

        try {
            // 在事务内重新检查重复（并发时上一个事务提交后数据已变）
            $dupInTx = $this->db->fetch(
                "SELECT id, filename FROM files WHERE user_id = ? AND parent_id = ? AND filename = ?",
                [$userId, $parentId, $filename]
            );
            if ($dupInTx) {
                $this->db->rollBack();
                @unlink($fullPath);
                if ($conflictResolution === 'overwrite') {
                    $this->fm->deleteFileById($dupInTx['id'], $userId);
                    // 注：原递归调用 uploadFile 已废弃（tmp_name 已消费），
                    // 改为返回冲突让前端重新发起。
                    return [
                        'success' => false,
                        'message' => '同名文件已清理，请重新上传',
                    ];
                } elseif ($conflictResolution === 'keep_both') {
                    $filename = $this->fm->getUniqueFilename($userId, $parentId, $filename);
                } elseif ($conflictResolution === 'cancel') {
                    return ['success' => false, 'message' => '已取消上传'];
                } else {
                    return [
                        'success' => false,
                        'duplicate_conflict' => true,
                        'message' => '当前文件夹已存在同名文件："' . $dupInTx['filename'] . '"',
                        'duplicate_filename' => $dupInTx['filename'],
                        'duplicate_id' => $dupInTx['id'],
                    ];
                }
            }

            $fileId = $this->db->insert('files', [
                'user_id' => $userId,
                'filename' => $filename,
                'filepath' => $filePath,
                'filesize' => $fileSize,
                'file_type' => $ext,
                'mime_type' => $mimeType,
                'is_dir' => 0,
                'parent_id' => $parentId,
                'path_hash' => md5($filePath),
                'content_hash' => $contentHash,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
            return ['success' => false, 'message' => '文件上传失败：' . $e->getMessage()];
        }

        // storage_used 已改为实时聚合查询，无需维护

        $this->logOperation('upload', $filename);

        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => '文件上传成功', 'filename' => $filename, 'size' => $fileSize];
    }

    public function getUploadedChunks($uploadId)
    {
        $userId = $this->auth->getUserId();

        // ── 优先从文件记录读取分片进度 ──
        $progressFile = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId . '.json';
        if (file_exists($progressFile)) {
            $content = file_get_contents($progressFile);
            $task = json_decode($content, true);
            if ($task && $task['user_id'] == $userId) {
                return $task['uploaded_chunks'] ?: [];
            }
        }

        // ── 兼容旧版数据库记录 ──
        $task = $this->db->fetch("SELECT * FROM upload_tasks WHERE upload_id = ? AND user_id = ?", [$uploadId, $userId]);
        if ($task) {
            return json_decode($task['uploaded_chunks'], true) ?: [];
        }

        return [];
    }

    public function cancelUpload($uploadId)
    {
        $userId = $this->auth->getUserId();

        // ── 清理文件记录的分片进度 ──
        $progressFile = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId . '.json';
        $lockFile = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId . '.lock';
        $chunkDir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId;

        if (file_exists($progressFile)) {
            $content = file_get_contents($progressFile);
            $task = json_decode($content, true);
            if ($task && $task['user_id'] == $userId) {
                $this->cleanChunkDir($chunkDir);
                @unlink($progressFile);
                @unlink($lockFile);
                return ['success' => true, 'message' => '上传已取消'];
            }
        }

        // ── 兼容旧版数据库记录 ──
        $task = $this->db->fetch("SELECT * FROM upload_tasks WHERE upload_id = ? AND user_id = ?", [$uploadId, $userId]);
        if ($task) {
            $chunkDir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $task['upload_id'];
            $this->cleanChunkDir($chunkDir);
            $this->db->delete('upload_tasks', 'upload_id = ? AND user_id = ?', [$uploadId, $userId]);
        }

        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => '上传已取消'];
    }

    public function resolveUploadConflict($uploadId, $conflictResolution)
    {
        $userId = $this->auth->getUserId();

        // 优先从 JSON 进度文件读取任务
        $progressFile = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId . '.json';
        if (file_exists($progressFile)) {
            $content = file_get_contents($progressFile);
            $task = json_decode($content, true);
            if ($task && ($task['user_id'] ?? 0) == $userId) {
                $chunkDir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId;
                if (!is_dir($chunkDir)) {
                    return ['success' => false, 'message' => '分片文件已过期，请重新上传'];
                }
                return $this->mergeChunks(
                    $task['parent_id'] ?? 0,
                    $task,
                    $chunkDir,
                    $task['filename'] ?? '',
                    $task['total_size'] ?? 0,
                    $conflictResolution
                );
            }
        }

        // 兼容旧版数据库记录
        $task = $this->db->fetch("SELECT * FROM upload_tasks WHERE upload_id = ? AND user_id = ?", [$uploadId, $userId]);
        if (!$task) {
            return ['success' => false, 'message' => '上传任务不存在'];
        }

        $chunkDir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId;
        if (!is_dir($chunkDir)) {
            $this->db->delete('upload_tasks', 'upload_id = ? AND user_id = ?', [$uploadId, $userId]);
            return ['success' => false, 'message' => '分片文件已过期，请重新上传'];
        }

        return $this->mergeChunks(
            $task['parent_id'],
            $task,
            $chunkDir,
            $task['filename'],
            $task['total_size'],
            $conflictResolution
        );
    }

    public function uploadChunk($parentId, $chunkInfo)
    {
        $userId = $this->auth->getUserId();
        $uploadId = $chunkInfo['upload_id'] ?? '';
        $chunkIndex = intval($chunkInfo['chunk_index'] ?? 0);
        $totalChunks = intval($chunkInfo['total_chunks'] ?? 0);
        $filename = Security::sanitizeFilename($chunkInfo['filename'] ?? '');
        $totalSize = intval($chunkInfo['total_size'] ?? 0);
        $chunkMd5 = $chunkInfo['chunk_md5'] ?? '';

        if (empty($uploadId) || empty($filename) || $totalChunks <= 0) {
            return ['success' => false, 'message' => '分片参数不完整'];
        }

        if (!Security::validateFileExtension($filename)) {
            return ['success' => false, 'message' => '不允许上传此类型的文件'];
        }

        if ($totalSize > $this->config->get('max_upload_size')) {
            return ['success' => false, 'message' => '文件大小超过限制'];
        }

        if ($chunkIndex === 0 && $totalSize > 0) {
            $storageCheck = $this->auth->checkStorageLimit($totalSize);
            if (!$storageCheck['status']) {
                return ['success' => false, 'message' => $storageCheck['message']];
            }
        }

        $chunkDir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId;
        if (!is_dir($chunkDir)) {
            mkdir($chunkDir, 0755, true);
        }
        $chunkFile = $chunkDir . DIRECTORY_SEPARATOR . $chunkIndex;

        if (isset($_FILES['chunk_data']) && $_FILES['chunk_data']['error'] === UPLOAD_ERR_OK && is_uploaded_file($_FILES['chunk_data']['tmp_name'])) {
            $isFirstChunk = $chunkIndex === 0;
            $totalChunks = intval($_POST['total_chunks'] ?? 1);
            $isLastChunk = $chunkIndex === $totalChunks - 1;

            // 智能内容检查策略
            // 1. 首分片必须检查
            // 2. 小文件（<10MB）所有分片都检查
            // 3. 非安全文件类型所有分片都检查
            $needContentCheck = false;

            if ($isFirstChunk) {
                $needContentCheck = true;
            } elseif ($totalSize < 10 * 1024 * 1024) { // 10MB
                $needContentCheck = true;
            } elseif (!$this->isSafeFileType($filename, $_FILES['chunk_data']['tmp_name'])) {
                $needContentCheck = true;
            }

            // 执行内容检查
            if ($needContentCheck && !$this->isSafeFileType($filename, $_FILES['chunk_data']['tmp_name'])) {
                if (!Security::validateFileContent($_FILES['chunk_data']['tmp_name'], $filename)) {
                    return ['success' => false, 'message' => '文件内容安全检查失败'];
                }
            }

            if (!move_uploaded_file($_FILES['chunk_data']['tmp_name'], $chunkFile)) {
                return ['success' => false, 'message' => '分片文件保存失败'];
            }

            if (!empty($chunkMd5) && ($isFirstChunk || $isLastChunk)) {
                $actualMd5 = md5_file($chunkFile);
                if ($actualMd5 !== $chunkMd5) {
                    @unlink($chunkFile);
                    return ['success' => false, 'message' => '分片 MD5 校验失败'];
                }
            }
        } else {
            $errorMsg = isset($_FILES['chunk_data']) ? '分片文件接收失败（错误码：' . $_FILES['chunk_data']['error'] . '）' : '未接收到分片数据';
            return ['success' => false, 'message' => $errorMsg];
        }

        // 分片进度锁按数据库类型分流：
        // - SQLite：维持 .progress.lock 文件锁 + JSON 进度文件（避免 SQLite 写竞争）
        // - MySQL/PostgreSQL：改用 DB 行锁（SELECT ... FOR UPDATE），充分利用行级锁能力
        $dbType = $this->db->getDbType();
        if ($dbType !== 'sqlite') {
            return $this->_saveChunkProgressDb($uploadId, $userId, $chunkIndex, $totalChunks, $parentId, $filename, $totalSize, $chunkInfo['file_md5'] ?? '');
        }

        // 文件锁：重试 5 次，避免并发写入旁路
        $progressLockFile = $chunkDir . DIRECTORY_SEPARATOR . '.progress.lock';
        $lockFp = null;
        $lockAcquired = false;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $lockFp = @fopen($progressLockFile, 'c+');
            if ($lockFp && flock($lockFp, LOCK_EX | LOCK_NB)) {
                $lockAcquired = true;
                break;
            }
            if ($lockFp) {
                fclose($lockFp);
                $lockFp = null;
            }
            if ($attempt < 4) {
                usleep(50000 * ($attempt + 1)); // 50ms, 100ms, 150ms, 200ms
            }
        }

        if ($lockAcquired) {
            try {
                $result = $this->_saveChunkProgress($uploadId, $userId, $chunkIndex, $totalChunks, $parentId, $filename, $totalSize, $chunkInfo['file_md5'] ?? '');
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
                return $result;
            } catch (\Exception $e) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
                return ['success' => false, 'message' => '分片上传失败：' . $e->getMessage()];
            }
        }

        // 最后一次兜底：走数据库乐观锁
        if ($lockFp) {
            fclose($lockFp);
        }
        return $this->_saveChunkProgress($uploadId, $userId, $chunkIndex, $totalChunks, $parentId, $filename, $totalSize, $chunkInfo['file_md5'] ?? '');
    }

    private function _saveChunkProgress($uploadId, $userId, $chunkIndex, $totalChunks, $parentId, $filename, $totalSize, $fileMd5 = '')
    {
        // ── 使用文件记录分片进度，减少SQLite写入竞争 ──
        $progressFile = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId . '.json';
        $lockFile = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId . '.lock';

        $lockFp = fopen($lockFile, 'c+');
        if (!$lockFp || !flock($lockFp, LOCK_EX)) {
            if ($lockFp) fclose($lockFp);
            return ['success' => false, 'message' => '无法获取文件锁'];
        }

        try {
            $task = null;
            if (file_exists($progressFile)) {
                $content = file_get_contents($progressFile);
                $task = json_decode($content, true);
            }

            if (!$task) {
                $task = [
                    'user_id' => $userId,
                    'upload_id' => $uploadId,
                    'filename' => $filename,
                    'total_size' => $totalSize,
                    'total_chunks' => $totalChunks,
                    'uploaded_chunks' => [],
                    'file_md5' => $fileMd5,
                    'parent_id' => $parentId,
                    'created_at' => time(),
                    'updated_at' => time(),
                ];
            }

            $uploadedChunks = $task['uploaded_chunks'] ?: [];

            if (in_array($chunkIndex, $uploadedChunks)) {
                // 分片已存在：仍需检查是否全部分片都已上传，如果是则触发合并
                if (count($uploadedChunks) >= $totalChunks) {
                    flock($lockFp, LOCK_UN);
                    fclose($lockFp);
                    $chunkDir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId;
                    return $this->mergeChunks($task['parent_id'] ?? $parentId, $task, $chunkDir, $task['filename'] ?? $filename, $task['total_size'] ?? $totalSize);
                }
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
                return ['success' => true, 'message' => '分片已存在', 'uploaded_chunks' => count($uploadedChunks), 'total_chunks' => $totalChunks, 'skipped' => true];
            }

            $uploadedChunks[] = $chunkIndex;
            $task['uploaded_chunks'] = $uploadedChunks;
            $task['updated_at'] = time();

            file_put_contents($progressFile, json_encode($task), LOCK_EX);

            flock($lockFp, LOCK_UN);
            fclose($lockFp);

            if (count($uploadedChunks) >= $totalChunks) {
                $chunkDir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId;
                return $this->mergeChunks($task['parent_id'] ?? $parentId, $task, $chunkDir, $task['filename'] ?? $filename, $task['total_size'] ?? $totalSize);
            }

            return ['success' => true, 'message' => '分片上传成功', 'uploaded_chunks' => count($uploadedChunks), 'total_chunks' => $totalChunks];
        } catch (\Exception $e) {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
            return ['success' => false, 'message' => '分片上传失败：' . $e->getMessage()];
        }
    }

    /**
     * MySQL/PostgreSQL 分片进度持久化：使用 DB 行锁（SELECT ... FOR UPDATE）替代文件锁。
     *
     * 与 SQLite 分支的 _saveChunkProgress 对应：
     * - 锁源：upload_tasks 表行锁（事务内 SELECT ... FOR UPDATE）
     * - 存储：upload_tasks 表的 uploaded_chunks 字段（JSON）
     * - 合并触发：事务外调用 mergeChunks（文件 I/O 不应持有行锁）
     */
    private function _saveChunkProgressDb($uploadId, $userId, $chunkIndex, $totalChunks, $parentId, $filename, $totalSize, $fileMd5 = '')
    {
        $triggerMerge = false;
        $taskForMerge = null;

        try {
            $result = ConcurrencyGuard::getInstance()->transactionImmediate(function () use (
                $uploadId, $userId, $chunkIndex, $totalChunks, $parentId, $filename, $totalSize, $fileMd5,
                &$triggerMerge, &$taskForMerge
            ) {
                // SELECT ... FOR UPDATE 锁定该 upload_id + user_id 的记录（事务内有效）
                // PostgreSQL 与 MySQL 语法一致，直接复用
                $task = $this->db->fetch(
                    "SELECT id, uploaded_chunks, total_chunks, parent_id, filename, total_size, file_md5 "
                    . "FROM upload_tasks WHERE upload_id = ? AND user_id = ? FOR UPDATE",
                    [$uploadId, $userId]
                );

                $now = time();

                if (!$task) {
                    // 首次插入：进度初始化为 [chunkIndex]
                    $uploadedChunks = [$chunkIndex];
                    $this->db->insert('upload_tasks', [
                        'user_id' => $userId,
                        'upload_id' => $uploadId,
                        'filename' => $filename,
                        'total_size' => $totalSize,
                        'total_chunks' => $totalChunks,
                        'uploaded_chunks' => json_encode($uploadedChunks),
                        'file_md5' => $fileMd5,
                        'parent_id' => $parentId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    if (count($uploadedChunks) >= $totalChunks) {
                        $triggerMerge = true;
                        $taskForMerge = [
                            'upload_id' => $uploadId,
                            'filename' => $filename,
                            'total_size' => $totalSize,
                            'total_chunks' => $totalChunks,
                            'parent_id' => $parentId,
                            'file_md5' => $fileMd5,
                            'uploaded_chunks' => $uploadedChunks,
                        ];
                        return ['success' => true, 'message' => '分片上传完成，准备合并'];
                    }

                    return ['success' => true, 'message' => '分片上传成功', 'uploaded_chunks' => count($uploadedChunks), 'total_chunks' => $totalChunks];
                }

                // 已有记录：合并分片
                $uploadedChunks = json_decode($task['uploaded_chunks'], true) ?: [];

                if (in_array($chunkIndex, $uploadedChunks, true)) {
                    // 分片已存在：若已达 total_chunks 则触发合并
                    if (count($uploadedChunks) >= $totalChunks) {
                        $triggerMerge = true;
                        $taskForMerge = [
                            'upload_id' => $uploadId,
                            'filename' => $task['filename'],
                            'total_size' => $task['total_size'],
                            'total_chunks' => $task['total_chunks'],
                            'parent_id' => $task['parent_id'],
                            'file_md5' => $task['file_md5'],
                            'uploaded_chunks' => $uploadedChunks,
                        ];
                        return ['success' => true, 'message' => '分片已存在，准备合并'];
                    }
                    return ['success' => true, 'message' => '分片已存在', 'uploaded_chunks' => count($uploadedChunks), 'total_chunks' => $totalChunks, 'skipped' => true];
                }

                $uploadedChunks[] = $chunkIndex;

                $this->db->update(
                    'upload_tasks',
                    [
                        'uploaded_chunks' => json_encode(array_values($uploadedChunks)),
                        'updated_at' => $now,
                    ],
                    'id = ?',
                    [$task['id']]
                );

                if (count($uploadedChunks) >= $totalChunks) {
                    $triggerMerge = true;
                    $taskForMerge = [
                        'upload_id' => $uploadId,
                        'filename' => $task['filename'],
                        'total_size' => $task['total_size'],
                        'total_chunks' => $task['total_chunks'],
                        'parent_id' => $task['parent_id'],
                        'file_md5' => $task['file_md5'],
                        'uploaded_chunks' => $uploadedChunks,
                    ];
                    return ['success' => true, 'message' => '分片上传完成，准备合并'];
                }

                return ['success' => true, 'message' => '分片上传成功', 'uploaded_chunks' => count($uploadedChunks), 'total_chunks' => $totalChunks];
            });

            // 事务外触发 mergeChunks（文件 I/O 不应持有行锁）
            if ($triggerMerge && $taskForMerge !== null) {
                $chunkDir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId;
                return $this->mergeChunks(
                    $taskForMerge['parent_id'],
                    $taskForMerge,
                    $chunkDir,
                    $taskForMerge['filename'],
                    $taskForMerge['total_size']
                );
            }

            return $result;
        } catch (\Exception $e) {
            return ['success' => false, 'message' => '分片上传失败：' . $e->getMessage()];
        }
    }

    private function mergeChunks($parentId, $task, $chunkDir, $filename, $totalSize, $conflictResolution = null)
    {
        ignore_user_abort(true);
        $userId = $this->auth->getUserId();

        // === Task 1: 基于 upload_id 的 merge 互斥锁 ===
        $uploadId = $task['upload_id'] ?? basename($chunkDir);
        $mergeLockPath = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId . '.merge.lock';
        $mergeLockFp = @fopen($mergeLockPath, 'c+');
        if ($mergeLockFp !== false) {
            flock($mergeLockFp, LOCK_EX); // 阻塞式获取锁，直接等待
        }

        try {
            // 拿到锁后先检查目标文件是否已存在（已被其他请求 merge 完成）
            $parent = $this->fm->getFileById($parentId);
            $parentPath = $parent ? $parent['filepath'] : '';
            $sanitizedFilename = Security::sanitizeFilename($filename);
            $filePath = $parentPath ? $parentPath . DIRECTORY_SEPARATOR . $sanitizedFilename : $sanitizedFilename;
            $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $filePath;
            if (file_exists($fullPath) && filesize($fullPath) == $totalSize) {
                return ['success' => true, 'message' => '文件已上传完成', 'filename' => $sanitizedFilename, 'size' => $totalSize, 'merged' => true, 'already_merged' => true];
            }

            $storageCheck = $this->auth->checkStorageLimit($totalSize);
            if (!$storageCheck['status']) {
                $this->cleanChunkDir($chunkDir);
                return ['success' => false, 'message' => $storageCheck['message']];
            }

            $duplicate = $this->db->fetch(
                "SELECT id, filename, filesize, filepath FROM files WHERE user_id = ? AND parent_id = ? AND filename = ?",
                [$userId, $parentId, $sanitizedFilename]
            );

            if ($duplicate) {
                if ($conflictResolution === 'overwrite') {
                    $this->fm->deleteFileById($duplicate['id'], $userId);
                } elseif ($conflictResolution === 'keep_both') {
                    $sanitizedFilename = $this->fm->getUniqueFilename($userId, $parentId, $sanitizedFilename);
                } elseif ($conflictResolution === 'cancel') {
                    $this->cleanChunkDir($chunkDir);
                    $this->db->delete('upload_tasks', 'upload_id = ? AND user_id = ?', [$task['upload_id'], $userId]);
                    return [
                        'success' => false,
                        'message' => '已取消上传',
                    ];
                } else {
                    return [
                        'success' => false,
                        'duplicate_conflict' => true,
                        'message' => '当前文件夹已存在同名文件："' . $duplicate['filename'] . '"',
                        'duplicate_filename' => $duplicate['filename'],
                        'duplicate_id' => $duplicate['id'],
                        'upload_id' => $task['upload_id'],
                        'filename' => $sanitizedFilename,
                        'total_size' => $totalSize,
                        'total_chunks' => $task['total_chunks'],
                    ];
                }
            }

            $filePath = $parentPath ? $parentPath . DIRECTORY_SEPARATOR . $sanitizedFilename : $sanitizedFilename;
            $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $filePath;

            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // 使用临时文件 + rename 模式：写入过程中其他读取者看到的是旧文件或不存在，
            // rename 完成后看到完整新文件，不会看到半写状态。
            $tempPath = $fullPath . '.tmp';

            try {
                // 'cb' 模式：仅写、不存在则创建、不截断（避免 fopen 立即截断引发竞态）
                $output = fopen($tempPath, 'cb');
                if ($output === false) {
                    // Task 2: 只清理临时文件，不清理分片目录，允许前端重试
                    if (file_exists($tempPath)) {
                        @unlink($tempPath);
                    }
                    // Task 3: 记录日志
                    \App\Core\AsyncLogger::getInstance()->warning(
                        '[output_create_fail] upload_id=' . $uploadId . ' filename=' . $filename,
                        ['upload_id' => $uploadId, 'filename' => $filename]
                    );
                    return ['success' => false, 'message' => '无法创建输出文件'];
                }

                // fopen 后立即 flock(LOCK_EX) 防止并发 merge 损坏文件；指数退避重试
                $lockAcquired = false;
                $maxLockAttempts = 5;
                $baseDelay = 50000; // 50ms
                for ($attempt = 0; $attempt < $maxLockAttempts; $attempt++) {
                    if (flock($output, LOCK_EX)) {
                        $lockAcquired = true;
                        break;
                    }
                    if ($attempt < $maxLockAttempts - 1) {
                        usleep($baseDelay * pow(2, $attempt));
                    }
                }
                if (!$lockAcquired) {
                    fclose($output);
                    // Task 2: 只清理临时文件，不清理分片目录，允许前端重试
                    if (file_exists($tempPath)) {
                        @unlink($tempPath);
                    }
                    // Task 3: 记录日志
                    \App\Core\AsyncLogger::getInstance()->warning(
                        '[merge_lock_timeout] upload_id=' . $uploadId . ' filename=' . $filename,
                        ['upload_id' => $uploadId, 'filename' => $filename]
                    );
                    return ['success' => false, 'message' => '获取文件锁超时，请稍后重试'];
                }

                // 持锁后清空 .tmp 残留数据，防止上次失败留下的旧数据污染本次写入
                ftruncate($output, 0);
                rewind($output);

                $bufferSize = 65536;
                for ($i = 0; $i < $task['total_chunks']; $i++) {
                    $chunkFile = $chunkDir . DIRECTORY_SEPARATOR . $i;
                    if (!file_exists($chunkFile)) {
                        fclose($output);
                        if (file_exists($tempPath)) {
                            @unlink($tempPath);
                        }
                        // 不清理分片目录：保留已上传分片和进度文件，允许前端重试缺失分片
                        // Task 3: 记录日志
                        \App\Core\AsyncLogger::getInstance()->warning(
                            '[chunk_missing] upload_id=' . $uploadId . ' filename=' . $filename . ' missing_chunk=' . $i . ' total_chunks=' . $task['total_chunks'],
                            ['upload_id' => $uploadId, 'filename' => $filename, 'missing_chunk' => $i, 'total_chunks' => $task['total_chunks']]
                        );
                        return [
                            'success' => false,
                            'message' => '分片文件缺失',
                            'missing_chunk' => $i,
                            'uploaded_chunks' => $task['uploaded_chunks'] ?? [],
                        ];
                    }

                    $input = fopen($chunkFile, 'rb');
                    if ($input === false) {
                        fclose($output);
                        if (file_exists($tempPath)) {
                            @unlink($tempPath);
                        }
                        // 读取失败也不清理，允许重试
                        // Task 3: 记录日志
                        \App\Core\AsyncLogger::getInstance()->warning(
                            '[chunk_read_fail] upload_id=' . $uploadId . ' filename=' . $filename . ' chunk=' . $i,
                            ['upload_id' => $uploadId, 'filename' => $filename, 'chunk' => $i]
                        );
                        return [
                            'success' => false,
                            'message' => '无法读取分片文件',
                            'missing_chunk' => $i,
                        ];
                    }

                    while (!feof($input)) {
                        $buffer = fread($input, $bufferSize);
                        if ($buffer === false || fwrite($output, $buffer) === false) {
                            fclose($input);
                            fclose($output);
                            // Task 2: 只清理临时文件，不清理分片目录，允许前端重试
                            if (file_exists($tempPath)) {
                                @unlink($tempPath);
                            }
                            // Task 3: 记录日志
                            \App\Core\AsyncLogger::getInstance()->warning(
                                '[chunk_write_fail] upload_id=' . $uploadId . ' filename=' . $filename . ' chunk=' . $i,
                                ['upload_id' => $uploadId, 'filename' => $filename, 'chunk' => $i]
                            );
                            return ['success' => false, 'message' => '分片写入失败'];
                        }
                    }
                    fclose($input);
                    unlink($chunkFile);
                }

                fflush($output);
                fclose($output); // 自动释放锁

                // 原子 rename 到目标路径
                if (!@rename($tempPath, $fullPath)) {
                    if (file_exists($tempPath)) {
                        @unlink($tempPath);
                    }
                    $this->cleanChunkDir($chunkDir);
                    // Task 3: 记录日志
                    \App\Core\AsyncLogger::getInstance()->error(
                        '[rename_fail] upload_id=' . $uploadId . ' filename=' . $filename . ' temp=' . $tempPath . ' target=' . $fullPath,
                        ['upload_id' => $uploadId, 'filename' => $filename, 'temp' => $tempPath, 'target' => $fullPath]
                    );
                    return ['success' => false, 'message' => '文件重命名失败'];
                }

                $this->cleanChunkDir($chunkDir);
                // 合并成功后清理进度文件和锁文件
                $progressFile = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId . '.json';
                $lockFile = UPLOAD_PATH . DIRECTORY_SEPARATOR . $uploadId . '.lock';
                if (is_file($progressFile)) @unlink($progressFile);
                if (is_file($lockFile)) @unlink($lockFile);

                $actualSize = filesize($fullPath);
                if ($actualSize === false) {
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                    return ['success' => false, 'message' => '无法获取文件大小'];
                }

                $ext = strtolower(pathinfo($sanitizedFilename, PATHINFO_EXTENSION));
                $mimeType = $this->getMimeType($fullPath);

                // 大文件（>100MB）跳过全量哈希计算以节省 I/O
                $contentHash = $totalSize > 104857600 ? '' : $this->calculateSHA256($fullPath);

                // ── 文件I/O完成后再开事务，缩短锁持有时间 ──
                // 使用 BEGIN IMMEDIATE 立即获取 RESERVED 锁，使事务内的冲突检查持锁，
                // 真正消除 TOCTOU 竞态（BEGIN DEFERRED 不持锁，检查可被并发写入插入）
                try {
                    ConcurrencyGuard::getInstance()->transactionImmediate(function () use (
                        $userId, $parentId, $sanitizedFilename, $filePath, $actualSize,
                        $ext, $mimeType, $contentHash
                    ) {
                        // 事务内二次检查：防止并发上传同名文件（唯一索引是最后防线）
                        $conflict = $this->db->fetch(
                            "SELECT id FROM files WHERE user_id = ? AND parent_id = ? AND filename = ?",
                            [$userId, $parentId, $sanitizedFilename]
                        );
                        if ($conflict) {
                            throw new \RuntimeException('FILE_CONFLICT');
                        }

                        $now = time();
                        $this->db->insert('files', [
                            'user_id' => $userId,
                            'filename' => $sanitizedFilename,
                            'filepath' => $filePath,
                            'filesize' => $actualSize,
                            'file_type' => $ext,
                            'mime_type' => $mimeType,
                            'is_dir' => 0,
                            'parent_id' => $parentId,
                            'path_hash' => md5($filePath),
                            'content_hash' => $contentHash,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        // storage_used 已改为实时聚合查询，无需维护
                    });
                } catch (\Throwable $e) {
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                    $msg = ($e instanceof \RuntimeException && str_contains($e->getMessage(), 'FILE_CONFLICT'))
                        ? '文件已存在：' . $sanitizedFilename
                        : '数据库写入失败：' . $e->getMessage();
                    return ['success' => false, 'message' => $msg];
                }
            } catch (\Exception $e) {
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
                $this->cleanChunkDir($chunkDir);
                // Task 3: 记录日志
                \App\Core\AsyncLogger::getInstance()->error(
                    '[merge_exception] upload_id=' . $uploadId . ' filename=' . $filename . ' error=' . $e->getMessage(),
                    ['upload_id' => $uploadId, 'filename' => $filename, 'error' => $e->getMessage()]
                );
                return ['success' => false, 'message' => '分片合并失败：' . $e->getMessage()];
            }

            $this->logOperation('upload_chunk', $sanitizedFilename);

            $this->db->invalidateTableCache("files");

            return ['success' => true, 'message' => '文件上传成功', 'filename' => $sanitizedFilename, 'size' => $actualSize, 'merged' => true];
        } finally {
            // Task 1: 释放 merge 互斥锁
            if ($mergeLockFp !== false) {
                flock($mergeLockFp, LOCK_UN);
                fclose($mergeLockFp);
            }
        }
    }

    private function cleanChunkDir($dir)
    {
        if (is_dir($dir)) {
            // glob('{*,.*}', GLOB_BRACE) 匹配普通文件和隐藏文件（如 .progress.lock）
            // 注意：.* 也会匹配 . 和 ..，必须显式跳过，否则 removeDirRecursive
            // 会递归到父目录删除兄弟上传会话目录，造成数据丢失。
            $items = glob($dir . DIRECTORY_SEPARATOR . '{*,.*}', GLOB_BRACE);
            foreach ($items as $item) {
                $base = basename($item);
                if ($base === '.' || $base === '..') continue;
                if (is_dir($item)) {
                    $this->fm->removeDirRecursive($item);
                } else {
                    @unlink($item);
                }
            }
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
        $lockFile = $dir . '.lock';
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
    }
}
