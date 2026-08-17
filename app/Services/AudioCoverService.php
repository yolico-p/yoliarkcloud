<?php

namespace App\Services;

class AudioCoverService
{
    protected $cacheDir;
    protected $jpegQuality = 85;
    protected $cacheTtl = 90 * 86400;

    public function __construct()
    {
        $this->cacheDir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'covers';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * 判断音频文件是否包含封面。
     * 会复用已缓存的封面或失败标记，避免重复解析文件。
     */
    public function hasCover($audioPath, $ext, $cacheKey)
    {
        $cachePath = $this->getCachePath($cacheKey);
        if (file_exists($cachePath) && $this->isCacheValid($cachePath)) {
            return true;
        }

        $failPath = $this->getFailPath($cacheKey);
        if (file_exists($failPath) && $this->isCacheValid($failPath)) {
            return false;
        }

        $coverData = $this->parseCoverFromAudio($audioPath, $ext);
        if ($coverData === null || strlen($coverData) === 0) {
            @file_put_contents($failPath, 'no_cover');
            return false;
        }

        @file_put_contents($cachePath, $coverData);
        return true;
    }

    /**
     * 提取音频封面。
     * @param int $maxWidth  0 表示不限制尺寸，返回原图
     * @param int $maxHeight 0 表示不限制尺寸，返回原图
     */
    public function extract($audioPath, $ext, $cacheKey, $maxWidth = 64, $maxHeight = 64)
    {
        $cachePath = $this->getCachePath($cacheKey);

        if (file_exists($cachePath) && $this->isCacheValid($cachePath)) {
            $coverData = file_get_contents($cachePath);
            if ($maxWidth <= 0 || $maxHeight <= 0) {
                return $coverData;
            }
            return $this->resizeCover($coverData, $maxWidth, $maxHeight);
        }

        $failPath = $this->getFailPath($cacheKey);
        if (file_exists($failPath) && $this->isCacheValid($failPath)) {
            return null;
        }

        $coverData = $this->parseCoverFromAudio($audioPath, $ext);
        if ($coverData === null || strlen($coverData) === 0) {
            @file_put_contents($failPath, 'no_cover');
            return null;
        }

        // 始终缓存原始封面数据，避免首次请求为小图时把低分辨率写入缓存
        try {
            file_put_contents($cachePath, $coverData);
        } catch (\Exception $e) {
            // 缓存保存失败不影响返回
        }

        if ($maxWidth > 0 && $maxHeight > 0 && function_exists('imagecreatefromstring')) {
            return $this->resizeCover($coverData, $maxWidth, $maxHeight);
        }

        return $coverData;
    }

    protected function getCachePath($cacheKey)
    {
        // _orig 用于区分旧版 64x64 缓存，避免把低分辨率图当作原始封面放大
        return $this->cacheDir . DIRECTORY_SEPARATOR . 'cover_' . $cacheKey . '_orig.jpg';
    }

    protected function getFailPath($cacheKey)
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . 'cover_' . $cacheKey . '.fail';
    }

    protected function parseCoverFromAudio($audioPath, $ext)
    {
        $handle = fopen($audioPath, 'rb');
        if (!$handle) return null;

        $data = '';
        $maxRead = 2 * 1024 * 1024;
        while (!feof($handle) && strlen($data) < $maxRead) {
            $chunk = fread($handle, 8192);
            if ($chunk === false) break;
            $data .= $chunk;
        }
        fclose($handle);

        $coverData = null;

        if ($ext === 'mp3') {
            $coverData = $this->extractID3v2Cover($data);
        }

        if ($coverData === null) {
            $coverData = $this->extractGenericCover($data);
        }

        return $coverData;
    }

    protected function extractID3v2Cover($data)
    {
        $pos = strpos($data, 'APIC');
        if ($pos === false) {
            return null;
        }

        $imageStart = strpos($data, "\xFF\xD8\xFF", $pos);
        if ($imageStart === false) {
            $imageStart = strpos($data, "\x89PNG", $pos);
        }

        if ($imageStart === false) {
            return null;
        }

        $imageEndJpeg = strpos($data, "\xFF\xD9", $imageStart);
        $imageEndPng = strpos($data, "IEND", $imageStart);

        $imageEnd = false;
        if ($imageEndJpeg !== false && $imageEndPng !== false) {
            $imageEnd = min($imageEndJpeg, $imageEndPng);
        } elseif ($imageEndJpeg !== false) {
            $imageEnd = $imageEndJpeg + 2;
        } elseif ($imageEndPng !== false) {
            $imageEnd = $imageEndPng + 8;
        }

        if ($imageEnd === false) {
            return null;
        }

        return substr($data, $imageStart, $imageEnd - $imageStart);
    }

    protected function extractGenericCover($data)
    {
        $imageStart = strpos($data, "\xFF\xD8\xFF");
        if ($imageStart !== false) {
            $imageEnd = strpos($data, "\xFF\xD9", $imageStart);
            if ($imageEnd !== false) {
                return substr($data, $imageStart, $imageEnd - $imageStart + 2);
            }
        }

        $imageStart = strpos($data, "\x89PNG");
        if ($imageStart !== false) {
            $imageEnd = strpos($data, "IEND", $imageStart);
            if ($imageEnd !== false) {
                return substr($data, $imageStart, $imageEnd - $imageStart + 8);
            }
        }

        return null;
    }

    protected function resizeCover($coverData, $maxWidth, $maxHeight)
    {
        $image = @imagecreatefromstring($coverData);
        if ($image === false) {
            return $coverData;
        }

        $originalWidth = imagesx($image);
        $originalHeight = imagesy($image);

        $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
        if ($ratio >= 1) {
            imagedestroy($image);
            return $coverData;
        }

        $newWidth = intval($originalWidth * $ratio);
        $newHeight = intval($originalHeight * $ratio);
        $thumbnail = imagecreatetruecolor($newWidth, $newHeight);

        imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

        ob_start();
        imagejpeg($thumbnail, null, $this->jpegQuality);
        $thumbnailData = ob_get_clean();

        imagedestroy($image);
        imagedestroy($thumbnail);

        return $thumbnailData;
    }

    protected function isCacheValid($cachePath)
    {
        return time() - filemtime($cachePath) <= $this->cacheTtl;
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
}
