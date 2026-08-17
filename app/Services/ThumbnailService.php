<?php

namespace App\Services;

use App\Core\Security;

class ThumbnailService
{
    protected $cacheDir;
    protected $maxWidth = 128;
    protected $maxHeight = 128;
    protected $jpegQuality = 80;
    protected $useWebP = true;
    // 超过此大小的源文件不生成缩略图（GD 解码大图极耗内存且极慢）
    protected $maxSourceSize;

    public function __construct()
    {
        $this->cacheDir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'thumbnails';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        $this->useWebP = function_exists('imagewebp');
        // 10MB — 超过此值 GD 解码通常需 5-15 秒，不值得在缩略图请求中等待
        $this->maxSourceSize = \App\Core\Config::getInstance()->get('thumbnail_max_source_size', 10 * 1024 * 1024);
    }

    public function generate($sourcePath, $ext, $cacheKey)
    {
        $extension = $this->useWebP ? 'webp' : 'jpg';
        $cachePath = $this->cacheDir . DIRECTORY_SEPARATOR . 'thumb_' . $cacheKey . '.' . $extension;
        $failPath = $this->cacheDir . DIRECTORY_SEPARATOR . 'thumb_' . $cacheKey . '.fail';

        // 负缓存：之前生成失败过的文件直接返回 false，不再重复尝试解码
        if (file_exists($failPath)) {
            return false;
        }

        // 第一次检查（无锁）：命中直接返回，避免每次请求都获取锁
        if (file_exists($cachePath) && $this->isCacheValid($cachePath)) {
            return $cachePath;
        }

        // 源文件大小检查：超过阈值直接返回失败（避免 file_get_contents 全量读入大文件）
        $sourceSize = @filesize($sourcePath);
        if ($sourceSize === false || $sourceSize > $this->maxSourceSize) {
            @file_put_contents($failPath, 'size:' . ($sourceSize ?: 0));
            return false;
        }

        if (!function_exists('imagecreatefromstring')) {
            @file_put_contents($failPath, 'nogd');
            return false;
        }

        // 双重检查锁：获取独占锁后再检查一次缓存，防止缓存击穿（多个并发请求同时解码同一张图）
        $lockPath = $cachePath . '.lock';
        $lockFp = @fopen($lockPath, 'c+');
        if ($lockFp === false) {
            // 锁文件无法创建：再检查一次缓存，否则返回 null
            if (file_exists($cachePath) && $this->isCacheValid($cachePath)) {
                return $cachePath;
            }
            return null;
        }

        // 非阻塞获取锁 + 短轮询重试（指数退避），避免长时间阻塞
        $lockAcquired = false;
        $maxAttempts = 5;
        $baseDelay = 10000; // 10ms
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if (flock($lockFp, LOCK_EX | LOCK_NB)) {
                $lockAcquired = true;
                break;
            }
            // 等待期间可能有其他进程已生成缓存，命中则直接返回（无需持锁）
            if (file_exists($cachePath) && $this->isCacheValid($cachePath)) {
                fclose($lockFp);
                return $cachePath;
            }
            if ($attempt < $maxAttempts - 1) {
                usleep($baseDelay * pow(2, $attempt));
            }
        }

        if (!$lockAcquired) {
            fclose($lockFp);
            // 锁获取失败：最后再检查一次缓存（其他进程可能已生成），否则返回 null 让上层重试
            if (file_exists($cachePath) && $this->isCacheValid($cachePath)) {
                return $cachePath;
            }
            return null;
        }

        try {
            // 持锁后二次检查缓存：等待期间其他进程可能已生成缓存，命中则直接返回
            if (file_exists($cachePath) && $this->isCacheValid($cachePath)) {
                return $cachePath;
            }

            $imageData = @file_get_contents($sourcePath);
            if ($imageData === false) {
                @file_put_contents($failPath, 'read_error');
                return false;
            }

            $image = @imagecreatefromstring($imageData);
            if ($image === false) {
                @file_put_contents($failPath, 'decode_error');
                return false;
            }

            $originalWidth = imagesx($image);
            $originalHeight = imagesy($image);

            $ratio = min($this->maxWidth / $originalWidth, $this->maxHeight / $originalHeight);
            if ($ratio >= 1) {
                // 小图：直接使用原图尺寸创建缓存，避免回退到无缓存的原始文件服务
                $ratio = 1;
            }

            $newWidth = intval($originalWidth * $ratio);
            $newHeight = intval($originalHeight * $ratio);
            $thumbnail = imagecreatetruecolor($newWidth, $newHeight);

            if (in_array($ext, ['png', 'webp'])) {
                imagealphablending($thumbnail, false);
                imagesavealpha($thumbnail, true);
            }

            imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

            if ($this->useWebP) {
                ob_start();
                imagewebp($thumbnail, null, $this->jpegQuality);
                $thumbnailData = ob_get_clean();
            } else {
                ob_start();
                imagejpeg($thumbnail, null, $this->jpegQuality);
                $thumbnailData = ob_get_clean();
            }

            imagedestroy($image);
            imagedestroy($thumbnail);

            // 临时文件 + rename 原子写入：避免其他进程读到半写状态的缓存
            $tempPath = $cachePath . '.tmp';
            if (@file_put_contents($tempPath, $thumbnailData, LOCK_EX) === false) {
                if (file_exists($tempPath)) {
                    @unlink($tempPath);
                }
                return null;
            }
            if (!@rename($tempPath, $cachePath)) {
                if (file_exists($tempPath)) {
                    @unlink($tempPath);
                }
                return null;
            }

            return $cachePath;
        } catch (\Throwable $e) {
            // 异常时不影响缓存目录其他文件，写失败标记后返回 false
            @file_put_contents($failPath, 'exception:' . substr($e->getMessage(), 0, 100));
            $tempPath = $cachePath . '.tmp';
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            return false;
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    // setLazyMode / shouldGenerate 已移除（从未被调用）

    /**
     * 检查某个 cacheKey 是否已标记为生成失败（负缓存）。
     * 用于 listFiles 时提前排除不会生成缩略图的文件。
     */
    public function isGenerationFailed($cacheKey)
    {
        $failPath = $this->cacheDir . DIRECTORY_SEPARATOR . 'thumb_' . $cacheKey . '.fail';
        return file_exists($failPath);
    }

    /**
     * 检查源文件大小是否超过缩略图生成阈值。
     */
    public function isSourceTooLarge($fileSize)
    {
        return $fileSize <= 0 || $fileSize > $this->maxSourceSize;
    }

    public function getCachePath($cacheKey)
    {
        $extension = $this->useWebP ? 'webp' : 'jpg';
        return $this->cacheDir . DIRECTORY_SEPARATOR . 'thumb_' . $cacheKey . '.' . $extension;
    }

    public function isCacheValid($cachePath)
    {
        if (!file_exists($cachePath)) {
            return false;
        }
        return time() - filemtime($cachePath) <= 30 * 86400;
    }

    public function clearCache()
    {
        if (is_dir($this->cacheDir)) {
            $files = glob($this->cacheDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    public function getCacheSize()
    {
        $totalSize = 0;
        $fileCount = 0;
        if (is_dir($this->cacheDir)) {
            $files = glob($this->cacheDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    $totalSize += filesize($file);
                    $fileCount++;
                }
            }
        }
        return ['size' => $totalSize, 'count' => $fileCount];
    }
}
