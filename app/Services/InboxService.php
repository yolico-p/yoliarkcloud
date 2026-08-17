<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Core\Config;
use App\Core\ConcurrencyGuard;
use App\Models\Inbox;
use App\Services\AuthService;
use App\Services\FileManagerService;
use App\Services\FileQueryService;
use App\Support\FileTypeTrait;
use App\Support\LogHelper;

class InboxService
{
    use LogHelper;
    use FileTypeTrait;

    private $db;
    private $config;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = Config::getInstance();
    }

    // Get inbox info for the logged-in user
    public function getInboxInfo(int $userId): array
    {
        $inboxEnabled = $this->config->get('inbox_enabled', false);
        if (!$inboxEnabled) {
            return ['success' => false, 'message' => '文件信箱未启用'];
        }

        $inboxUrl = $this->config->get('inbox_url', '');
        if (!$inboxUrl) {
            $inboxUrl = $this->generateInboxUrl($userId);
        }

        $files = Inbox::listByUser($userId);
        $totalFiles = Inbox::countByUser($userId);
        $totalSize = Inbox::totalSizeByUser($userId);

        $fileList = [];
        foreach ($files as $f) {
            $fileList[] = [
                'id' => $f->get('id'),
                'filename' => $f->get('filename'),
                'filesize' => $f->get('filesize'),
                'filesize_formatted' => Security::formatSize($f->get('filesize')),
                'file_type' => $f->get('file_type', ''),
                'icon' => $this->getFileIconType($f->get('file_type', ''), $f->get('filename', '')),
                'sender_name' => $f->get('sender_name', ''),
                'sender_message' => $f->get('sender_message', ''),
                'created_at' => $f->get('created_at'),
                'created_at_formatted' => date('Y-m-d H:i', $f->get('created_at')),
            ];
        }

        $baseUrl = $this->getBaseUrl();
        return [
            'success' => true,
            'inbox_url' => $baseUrl . 'index.php?page=inbox&token=' . $inboxUrl,
            'total_files' => $totalFiles,
            'total_size' => $totalSize,
            'total_size_formatted' => Security::formatSize($totalSize),
            'files' => $fileList,
        ];
    }

    // Toggle inbox enabled/disabled
    public function toggleInbox(bool $enabled): array
    {
        $this->config->set('inbox_enabled', $enabled);
        $inboxUrl = '';
        if ($enabled) {
            $inboxUrl = $this->config->get('inbox_url', '');
            if (!$inboxUrl) {
                $inboxUrl = $this->generateToken();
                $this->config->set('inbox_url', $inboxUrl);
            }
        }
        $this->config->save();

        $fullUrl = '';
        if ($enabled && $inboxUrl) {
            $fullUrl = $this->getBaseUrl() . 'index.php?page=inbox&token=' . $inboxUrl;
        }

        return [
            'success' => true,
            'message' => $enabled ? '文件信箱已启用' : '文件信箱已关闭',
            'inbox_url' => $fullUrl,
        ];
    }

    // Regenerate inbox URL
    public function regenerateUrl(): array
    {
        $newToken = $this->generateToken();
        $this->config->set('inbox_url', $newToken);
        $this->config->save();

        return [
            'success' => true,
            'inbox_url' => $this->getBaseUrl() . 'index.php?page=inbox&token=' . $newToken,
        ];
    }

    // Upload file to inbox (public, no auth required)
    public function uploadToInbox(string $inboxToken, array $fileInfo, string $senderName = '', string $senderMessage = ''): array
    {
        // 1. Verify inbox is enabled and token is valid
        $inboxEnabled = $this->config->get('inbox_enabled', false);
        if (!$inboxEnabled) {
            return ['success' => false, 'message' => '文件信箱未启用'];
        }

        $savedToken = $this->config->get('inbox_url', '');
        if (!$savedToken || $inboxToken !== $savedToken) {
            return ['success' => false, 'message' => '收件链接无效'];
        }

        // 2. Validate upload
        if (!isset($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
            return ['success' => false, 'message' => '无效的上传文件'];
        }

        $filename = Security::sanitizeFilename($fileInfo['name']);
        if (!Security::validateFileExtension($filename)) {
            return ['success' => false, 'message' => '不允许上传此类型的文件'];
        }

        $fileSize = $fileInfo['size'];
        $maxInboxSize = $this->config->get('max_upload_size', 524288000);
        if ($fileSize > $maxInboxSize) {
            return ['success' => false, 'message' => '文件大小超过限制'];
        }

        if (!Security::validateFileContent($fileInfo['tmp_name'], $filename)) {
            return ['success' => false, 'message' => '文件内容安全检查失败'];
        }

        // 3. Find the user (single-user system, get first admin)
        $user = $this->db->fetch("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
        if (!$user) {
            return ['success' => false, 'message' => '系统配置错误'];
        }
        $userId = (int) $user['id'];

        // 4. Check storage limit
        $auth = new AuthService();
        $storageCheck = $auth->checkStorageLimit($fileSize);
        if (!$storageCheck['status']) {
            return ['success' => false, 'message' => $storageCheck['message']];
        }

        // 5. Sanitize sender info
        $senderName = mb_substr(trim(Security::escape($senderName)), 0, 50);
        $senderMessage = mb_substr(trim(Security::escape($senderMessage)), 0, 500);

        // 6. Save file to inbox directory
        $inboxDir = FILES_PATH . DIRECTORY_SEPARATOR . '__inbox__';
        if (!is_dir($inboxDir)) {
            mkdir($inboxDir, 0755, true);
        }

        // Add .htaccess to prevent direct access
        $htaccess = $inboxDir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }

        $uniqueName = time() . '_' . bin2hex(random_bytes(8)) . '_' . $filename;
        $fullPath = $inboxDir . DIRECTORY_SEPARATOR . $uniqueName;

        if (!move_uploaded_file($fileInfo['tmp_name'], $fullPath)) {
            return ['success' => false, 'message' => '文件保存失败'];
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeType = $this->getMimeType($fullPath);
        $filepath = '__inbox__' . DIRECTORY_SEPARATOR . $uniqueName;
        $now = time();

        // 7. Save to database
        try {
            $inboxFile = new Inbox();
            $inboxFile->fill([
                'user_id' => $userId,
                'filename' => $filename,
                'filepath' => $filepath,
                'filesize' => $fileSize,
                'file_type' => $ext,
                'mime_type' => $mimeType,
                'sender_name' => $senderName,
                'sender_message' => $senderMessage,
                'inbox_token' => $inboxToken,
                'created_at' => $now,
            ]);
            $inboxFile->save();
        } catch (\Exception $e) {
            @unlink($fullPath);
            return ['success' => false, 'message' => '文件投递失败，请稍后重试'];
        }

        $this->logOperation('inbox_upload', $filename);
        return ['success' => true, 'message' => '文件投递成功！'];
    }

    // Download inbox file
    public function downloadFile(int $fileId, int $userId): array
    {
        $file = Inbox::findByIdAndUser($fileId, $userId);
        if (!$file) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file->get('filepath');
        if (!file_exists($fullPath)) {
            return ['success' => false, 'message' => '文件已被移除'];
        }

        return [
            'success' => true,
            'path' => $fullPath,
            'filename' => $file->get('filename'),
            'mime' => $file->get('mime_type', 'application/octet-stream'),
            'size' => $file->get('filesize'),
        ];
    }

    // Move inbox file to user's file system
    public function moveToFilesystem(int $fileId, int $targetParentId, int $userId): array
    {
        $inboxFile = Inbox::findByIdAndUser($fileId, $userId);
        if (!$inboxFile) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        $sourcePath = FILES_PATH . DIRECTORY_SEPARATOR . $inboxFile->get('filepath');
        if (!file_exists($sourcePath)) {
            // Clean up DB record if file is missing
            $inboxFile->delete();
            return ['success' => false, 'message' => '源文件已被移除'];
        }

        // Get target folder path
        if ($targetParentId > 0) {
            $parent = (new FileQueryService())->getFileById($targetParentId);
            if (!$parent || $parent['user_id'] != $userId) {
                return ['success' => false, 'message' => '目标文件夹不存在'];
            }
            $targetDir = FILES_PATH . DIRECTORY_SEPARATOR . $parent['filepath'];
        } else {
            $targetDir = FILES_PATH;
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // Get unique filename in target
        $filename = $inboxFile->get('filename');
        $uniqueFilename = (new FileManagerService())->getUniqueFilename($userId, $targetParentId, $filename);
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $uniqueFilename;

        // Move file
        if (!@rename($sourcePath, $targetPath)) {
            return ['success' => false, 'message' => '文件移动失败'];
        }

        // Create file record in files table
        $filePath = ($targetParentId > 0 ? $parent['filepath'] . DIRECTORY_SEPARATOR : '') . $uniqueFilename;
        $now = time();

        try {
            ConcurrencyGuard::getInstance()->transactionImmediate(function () use ($userId, $targetParentId, $uniqueFilename, $filePath, $inboxFile, $now) {
                $this->db->insert('files', [
                    'user_id' => $inboxFile->get('user_id'),
                    'filename' => $uniqueFilename,
                    'filepath' => $filePath,
                    'filesize' => $inboxFile->get('filesize'),
                    'file_type' => $inboxFile->get('file_type', ''),
                    'mime_type' => $inboxFile->get('mime_type', ''),
                    'is_dir' => 0,
                    'parent_id' => $targetParentId,
                    'path_hash' => md5($filePath),
                    'content_hash' => '',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        } catch (\Throwable $e) {
            // Roll back file move
            @rename($targetPath, $sourcePath);
            return ['success' => false, 'message' => '文件转存失败：' . $e->getMessage()];
        }

        // Delete inbox record
        $inboxFile->delete();

        $this->logOperation('inbox_move', $uniqueFilename);
        $this->db->invalidateTableCache('files');

        return ['success' => true, 'message' => '文件已转存到网盘'];
    }

    // Delete inbox file
    public function deleteFile(int $fileId, int $userId): array
    {
        $file = Inbox::findByIdAndUser($fileId, $userId);
        if (!$file) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file->get('filepath');
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }

        $file->delete();
        $this->logOperation('inbox_delete', $file->get('filename'));

        return ['success' => true, 'message' => '文件已删除'];
    }

    // Verify inbox token (for public page)
    public function verifyToken(string $token): array
    {
        $inboxEnabled = $this->config->get('inbox_enabled', false);
        if (!$inboxEnabled) {
            return ['valid' => false, 'message' => '文件信箱未启用'];
        }

        $savedToken = $this->config->get('inbox_url', '');
        if (!$savedToken || $token !== $savedToken) {
            return ['valid' => false, 'message' => '收件链接无效'];
        }

        $appName = $this->config->get('app_name', '柚舟Cloud');
        return ['valid' => true, 'app_name' => $appName];
    }

    // Helper: generate secure token
    private function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    // Helper: generate full inbox URL
    private function generateInboxUrl(int $userId): string
    {
        $token = $this->generateToken();
        $this->config->set('inbox_url', $token);
        $this->config->save();
        return $token;
    }

    // Helper: get base URL
    private function getBaseUrl(): string
    {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $port = $_SERVER['SERVER_PORT'] ?? 80;
        $baseUrl = "{$protocol}://{$host}";
        if (($protocol === 'http' && $port != 80) || ($protocol === 'https' && $port != 443)) {
            $baseUrl .= ":{$port}";
        }
        $baseUrl .= '/';
        return $baseUrl;
    }

    // Helper: get file icon type for frontend
    private function getFileIconType(string $fileType, string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $iconMap = [
            'image' => ['jpg','jpeg','png','gif','bmp','svg','webp','ico','tiff'],
            'video' => ['mp4','avi','mkv','mov','wmv','flv','webm'],
            'audio' => ['mp3','wav','flac','aac','ogg','wma','m4a'],
            'pdf' => ['pdf'],
            'word' => ['doc','docx'],
            'excel' => ['xls','xlsx'],
            'ppt' => ['ppt','pptx'],
            'text' => ['txt','md','log','csv','json','xml','html','css','js'],
            'archive' => ['zip','rar','7z','tar','gz','bz2'],
            'code' => ['php','py','java','c','cpp','h','rb','go','rs','ts'],
        ];

        foreach ($iconMap as $icon => $extensions) {
            if (in_array($ext, $extensions)) return $icon;
        }
        if ($fileType === 'image') return 'image';
        if ($fileType === 'video') return 'video';
        if ($fileType === 'audio') return 'audio';
        return 'file';
    }
}
