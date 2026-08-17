<?php

namespace App\Controllers\File;

use App\Controllers\BaseController;
use App\Core\Security;
use App\Services\ThumbnailService;
use App\Services\AudioCoverService;
use App\Services\PreviewService;
use App\Support\HttpRangeTrait;

class DownloadController extends BaseController
{
    use HttpRangeTrait;

    private $previewService;

    public function __construct()
    {
        parent::__construct();
        $this->previewService = new PreviewService();
    }

    public function download()
    {
        $this->requireAuth();
        $this->rateLimit('download', 30, 60);

        $fileId = intval($this->input('file_id', 0));

        $result = $this->fileManager()->downloadFile($fileId);

        if (!$result['success']) {
            Security::jsonOutput($result, 404);
        }

        $fullPath = $result['path'];
        $filename = $result['filename'];
        $mimeType = $result['mime'];
        $fileSize = $result['size'];
        $contentHash = $result['content_hash'] ?? '';
        $isTemp = !empty($result['temp']);

        // 加密文件：先解密到临时文件再发送
        if (!empty($result['is_encrypted'])) {
            $tempResult = $this->encryptionService()->decryptFileToTemp($fileId);
            if ($tempResult) {
                $fullPath = $tempResult['path'];
                $fileSize = $tempResult['size'];
                $isTemp = true;
                // 解密后文件 hash 与原始 content_hash 不同，不发送校验头
                $contentHash = '';
            } else {
                Security::jsonOutput(['success' => false, 'message' => '加密文件解密失败，请重新登录'], 400);
            }
        }

        $this->fileManager()->recordAccess($fileId);

        // 通过 Trait 统一处理 HTTP Range / 206 响应 / 临时文件清理 / exit
        $this->sendFileWithRange($fullPath, $filename, $fileSize, $mimeType, $contentHash, $isTemp);
    }

    public function preview()
    {
        $this->requireAuth();

        $fileId = intval($this->input('file_id', 0));
        $file = $this->queryService()->getFileById($fileId);

        if (!$file) {
            Security::jsonOutput(['success' => false, 'message' => '文件不存在或无权访问'], 404);
        }

        // 大小检查前置：在解密/传输等耗时操作之前立即拒绝超大文件
        $previewType = $this->previewService->detectType($file['file_type']);
        $sizeLimit = $this->previewService->getSizeLimit($previewType);
        if ($file['filesize'] > $sizeLimit) {
            Security::jsonOutput(['success' => false, 'message' => '文件过大，无法预览']);
        }

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];

        if (!Security::isSafeFilePath($fullPath) || !file_exists($fullPath)) {
            Security::jsonOutput(['success' => false, 'message' => '文件访问被拒绝'], 403);
        }

        if (!empty($file['is_encrypted'])) {
            $tempResult = $this->encryptionService()->decryptFileToTemp($fileId);
            if ($tempResult) {
                $fullPath = $tempResult['path'];
            } else {
                Security::jsonOutput(['success' => false, 'message' => '加密文件解密失败，请重新登录'], 400);
            }
        }

        $this->fileManager()->recordAccess($fileId);

        if (in_array($previewType, [PreviewService::TYPE_TEXT, PreviewService::TYPE_MARKDOWN, PreviewService::TYPE_CSV], true)) {
            $read = $this->previewService->readTextContent($fullPath);
            if (!$read['success']) {
                Security::jsonOutput(['success' => false, 'message' => $read['message']]);
            }
            Security::jsonOutput([
                'success' => true,
                'preview_type' => $previewType,
                'content' => $read['content'],
                'filename' => Security::escape($file['filename']),
            ]);
        }

        if ($previewType === PreviewService::TYPE_EXCEL || $previewType === PreviewService::TYPE_WORD) {
            // 直接流式返回文件内容，避免前端走 download 动作触发下载限流（429）
            $cacheMaxAge = $this->previewService->getCacheMaxAge($previewType);
            header('Cache-Control: public, max-age=' . $cacheMaxAge);
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $cacheMaxAge) . ' GMT');
            $this->sendFileWithRange($fullPath, null, intval($file['filesize']), $file['mime_type'], '', !empty($file['is_encrypted']));
        }

        if ($previewType === PreviewService::TYPE_PDF) {
            // 对 PDF 预览启用浏览器缓存并强制 inline 显示，避免 PC 浏览器触发下载
            $cacheMaxAge = $this->previewService->getCacheMaxAge($previewType);
            header('Cache-Control: public, max-age=' . $cacheMaxAge);
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $cacheMaxAge) . ' GMT');
            // 通过 Trait 统一处理 HTTP Range / 206 响应 / 临时文件清理 / exit
            $this->sendFileWithRange($fullPath, $file['filename'], intval($file['filesize']), 'application/pdf', '', !empty($file['is_encrypted']), 'inline');
        }

        if (in_array($previewType, [PreviewService::TYPE_IMAGE, PreviewService::TYPE_VIDEO, PreviewService::TYPE_AUDIO], true)) {
            // 对预览资源启用浏览器缓存
            $cacheMaxAge = $this->previewService->getCacheMaxAge($previewType);
            header('Cache-Control: public, max-age=' . $cacheMaxAge);
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $cacheMaxAge) . ' GMT');
            // 通过 Trait 统一处理 HTTP Range / 206 响应 / 临时文件清理 / exit
            $this->sendFileWithRange($fullPath, null, intval($file['filesize']), $file['mime_type'], '', !empty($file['is_encrypted']));
        }

        if ($previewType === PreviewService::TYPE_ZIP) {
            $zip = new \ZipArchive();
            $openResult = $zip->open($fullPath, \ZipArchive::RDONLY);
            if ($openResult !== true) {
                Security::jsonOutput(['success' => false, 'message' => '无法打开 ZIP 文件']);
            }

            $totalCount = $zip->numFiles;
            $maxDisplay = 500;
            $displayCount = min($totalCount, $maxDisplay);
            $hasMore = $totalCount > $maxDisplay;

            $entries = [];
            $totalSize = 0;
            $compressedSize = 0;

            for ($i = 0; $i < $displayCount; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $name = $this->previewService->convertZipEntryName($stat['name']);
                $isDir = substr($name, -1) === '/';
                $size = isset($stat['size']) ? intval($stat['size']) : 0;
                $compSize = isset($stat['comp_size']) ? intval($stat['comp_size']) : 0;
                $mtime = isset($stat['mtime']) && $stat['mtime'] > 0
                    ? date('Y-m-d H:i:s', $stat['mtime'])
                    : date('Y-m-d H:i:s');

                $entries[] = [
                    'name' => $name,
                    'size' => $size,
                    'compressed_size' => $compSize,
                    'date' => $mtime,
                    'is_dir' => $isDir,
                ];

                $totalSize += $size;
                $compressedSize += $compSize;
            }

            $zip->close();

            Security::jsonOutput([
                'success' => true,
                'preview_type' => $previewType,
                'filename' => Security::escape($file['filename']),
                'total_count' => $totalCount,
                'display_count' => count($entries),
                'has_more' => $hasMore,
                'total_size' => $totalSize,
                'compressed_size' => $compressedSize,
                'entries' => $entries,
            ]);
        }

        Security::jsonOutput([
            'success' => true,
            'preview_type' => $previewType,
            'file_id' => $fileId,
            'filename' => Security::escape($file['filename']),
            'mime_type' => $file['mime_type'],
        ]);
    }

    public function thumbnail()
    {
        $this->requireAuth();

        $fileId = intval($this->input('file_id', 0));
        $file = $this->queryService()->getFileById($fileId);

        if (!$file || $file['is_dir']) {
            http_response_code(404);
            exit;
        }

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];

        if (!Security::isSafeFilePath($fullPath) || !file_exists($fullPath)) {
            http_response_code(404);
            exit;
        }

        // 清除输出压缩缓冲，防止二进制内容被 gzip/brotli 改变长度
        self::cleanOutputBuffer();

        $ext = strtolower($file['file_type']);

        if ($this->previewService->isAudioType($ext)) {
            $coverService = new AudioCoverService();
            $cacheKey = intval($fileId) . '_' . ($file['content_hash'] ?: md5($file['filepath'] . '_' . $file['updated_at']));
            $sizeParam = strtolower($this->input('size', ''));
            // 播放器使用大图：max 400x400；文件列表使用默认 64x64
            $maxWidth = $maxHeight = ($sizeParam === 'large') ? 400 : 64;
            $coverData = $coverService->extract($fullPath, $ext, $cacheKey, $maxWidth, $maxHeight);
            if ($coverData === null) {
                http_response_code(404);
                header('Cache-Control: public, max-age=86400');
                exit;
            }
            header('Content-Type: image/jpeg');
            header('Content-Length: ' . strlen($coverData));
            header('Cache-Control: public, max-age=604800');
            echo $coverData;
            exit;
        }

        $thumbnailService = new ThumbnailService();
        $cacheKey = intval($fileId) . '_' . ($file['content_hash'] ?: md5($file['filepath'] . '_' . $file['updated_at']));
        $thumbnailPath = $thumbnailService->generate($fullPath, $ext, $cacheKey);

        if ($thumbnailPath !== null && $thumbnailPath !== false) {
            // 根据实际缓存文件扩展名决定 Content-Type
            $thumbExt = strtolower(pathinfo($thumbnailPath, PATHINFO_EXTENSION));
            $thumbMime = $thumbExt === 'webp' ? 'image/webp' : 'image/jpeg';
            header('Content-Type: ' . $thumbMime);
            header('Content-Length: ' . filesize($thumbnailPath));
            header('Cache-Control: public, max-age=2592000');
            readfile($thumbnailPath);
            exit;
        }

        // 缩略图生成失败（文件过大/格式不支持/GD 解码失败）：返回 404，不发原始文件
        http_response_code(404);
        header('Cache-Control: no-store');
        exit;
    }

    public function recordAccess()
    {
        $this->requireAuth();

        $fileId = intval($this->input('file_id', 0));
        if ($fileId > 0) {
            $this->fileManager()->recordAccess($fileId);
        }

        Security::jsonOutput(['success' => true]);
    }
}
