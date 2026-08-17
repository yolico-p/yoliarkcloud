<?php

namespace App\Controllers\File;

use App\Controllers\BaseController;
use App\Core\Security;

class UploadController extends BaseController
{
    public function upload()
    {
        $this->requireAuth();
        $this->validateCSRF();

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $_FILES['file']['error'] ?? -1;
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => '文件超过服务器允许的最大上传大小',
                UPLOAD_ERR_FORM_SIZE => '文件超过表单允许的最大上传大小',
                UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
                UPLOAD_ERR_NO_FILE => '未选择文件',
                UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
                UPLOAD_ERR_CANT_WRITE => '文件写入磁盘失败',
            ];
            $this->error($errorMessages[$errorCode] ?? '文件上传失败');
        }

        // 尽早释放 session 锁，避免同一用户并发上传请求串行排队
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $fileSize = $_FILES['file']['size'] ?? 0;
        $this->adaptiveRateLimit('upload', $fileSize);

        $parentId = intval($this->input('parent_id', 0));
        $conflictResolution = $this->input('conflict_resolution', null);
        $relativePath = $this->input('relative_path', '');

        // 拖拽文件夹上传：根据相对路径自动创建目录层级
        if ($relativePath !== '') {
            $parentId = $this->fileManager()->ensureFolderPath($parentId, $relativePath);
        }

        $result = $this->uploadService()->uploadFile($parentId, $_FILES['file'], null, $conflictResolution);

        Security::jsonOutput($result);
    }

    public function uploadChunk()
    {
        $this->requireAuth();
        $this->validateCSRF();

        // 分片上传尽早释放 session 锁，避免同一用户并发分片请求串行排队
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // 分片上传使用宽松限流：仅记录不拒绝，避免大批量上传令牌耗尽导致分片缺失
        $this->adaptiveRateLimitSoft('upload_chunk');

        $parentId = intval($this->input('parent_id', 0));
        $relativePath = $this->input('relative_path', '');

        // 拖拽文件夹上传：根据相对路径自动创建目录层级
        if ($relativePath !== '') {
            $parentId = $this->fileManager()->ensureFolderPath($parentId, $relativePath);
        }

        $chunkInfo = [
            'upload_id' => $this->input('upload_id', ''),
            'chunk_index' => $this->input('chunk_index', 0),
            'total_chunks' => $this->input('total_chunks', 0),
            'filename' => $this->input('filename', ''),
            'total_size' => intval($this->input('total_size', 0)),
            'chunk_md5' => $this->input('chunk_md5', ''),
            'file_md5' => $this->input('file_md5', ''),
        ];

        $result = $this->uploadService()->uploadChunk($parentId, $chunkInfo);

        Security::jsonOutput($result);
    }

    public function resolveUploadConflict()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $uploadId = $this->input('upload_id', '');
        $conflictResolution = $this->input('conflict_resolution', '');

        if (empty($uploadId) || !in_array($conflictResolution, ['overwrite', 'keep_both', 'cancel'])) {
            $this->error('参数不完整');
        }

        $result = $this->uploadService()->resolveUploadConflict($uploadId, $conflictResolution);

        Security::jsonOutput($result);
    }

    public function cancelUpload()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $uploadId = $this->input('upload_id', '');

        if (empty($uploadId)) {
            $this->error('参数不完整');
        }

        $result = $this->uploadService()->cancelUpload($uploadId);

        Security::jsonOutput($result);
    }

    public function getUploadedChunks()
    {
        $this->requireAuth();

        $uploadId = $this->input('upload_id', '');
        if (empty($uploadId)) {
            $this->error('upload_id 不能为空');
        }

        $chunks = $this->uploadService()->getUploadedChunks($uploadId);

        Security::jsonOutput(['success' => true, 'uploaded_chunks' => $chunks]);
    }

    public function cleanupExpiredUploadTasks()
    {
        $this->requireAuth();
        $this->validateCSRF();
        $count = $this->uploadService()->cleanupExpiredUploadTasks();
        Security::jsonOutput(['success' => true, 'cleaned' => $count]);
    }
}
