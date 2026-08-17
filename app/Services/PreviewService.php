<?php

namespace App\Services;

use App\Core\Config;

/**
 * PreviewService - 预览业务编排
 *
 * 从 DownloadController 中抽离的预览相关业务规则：
 *  - 扩展名 → 预览类型映射（image/video/audio/markdown/text/csv/pdf/excel/word/unknown）
 *  - 各类型预览尺寸阈值（从 Config 读取，含默认 fallback）
 *  - 文本类（text/markdown/csv）内容读取与编码探测/转换
 *  - 音频类型判断（供缩略图流程复用，避免 audioTypes 数组在多处重复）
 *  - 预览资源浏览器缓存时长
 *
 * 控制器负责 HTTP 传输层（鉴权、限流、HTTP Range、响应头、exit），
 * 本 Service 只关心业务判断与数据准备。
 */
class PreviewService
{
    public const IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'tif'];
    public const VIDEO_TYPES = ['mp4', 'webm', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'm4v', '3gp', 'mpg', 'mpeg', 'ts', 'f4v', 'ogv', 'rm', 'rmvb', 'vob', 'mts', 'm2ts'];
    public const AUDIO_TYPES = ['mp3', 'wav', 'ogg', 'flac', 'aac', 'wma', 'aiff', 'aif', 'm4a', 'opus', 'ape', 'alac', 'ra', 'ram', 'ac3', 'amr', 'mid', 'midi'];
    public const MARKDOWN_TYPES = ['md'];
    public const TEXT_TYPES = ['txt', 'json', 'xml', 'html', 'css', 'js', 'log', 'ini', 'cfg', 'yml', 'yaml', 'py', 'rb', 'java', 'c', 'cpp', 'h', 'go', 'rs', 'sql', 'ts', 'jsx', 'tsx', 'vue', 'sh', 'bash', 'bat', 'ps1', 'r', 'm', 'swift', 'kt', 'scala', 'php'];
    public const CSV_TYPES = ['csv'];
    public const PDF_TYPES = ['pdf'];
    public const ZIP_TYPES = ['zip'];
    public const EXCEL_TYPES = ['xlsx', 'xls'];
    public const WORD_TYPES = ['docx'];

    public const TYPE_IMAGE = 'image';
    public const TYPE_ZIP = 'zip';
    public const TYPE_VIDEO = 'video';
    public const TYPE_AUDIO = 'audio';
    public const TYPE_MARKDOWN = 'markdown';
    public const TYPE_TEXT = 'text';
    public const TYPE_CSV = 'csv';
    public const TYPE_PDF = 'pdf';
    public const TYPE_EXCEL = 'excel';
    public const TYPE_WORD = 'word';
    public const TYPE_UNKNOWN = 'unknown';

    /** 文本类预览内容读取上限（500KB），避免大文本全量加载到内存 */
    public const TEXT_READ_LIMIT = 512000;

    /** mb_detect_encoding 探测顺序：覆盖中文常见编码与 UTF-8/ASCII */
    private const TEXT_ENCODING_DETECT_ORDER = ['UTF-8', 'GB2312', 'GBK', 'GB18030', 'BIG5', 'EUC-CN', 'ISO-8859-1', 'ASCII'];

    private $config;

    public function __construct()
    {
        $this->config = Config::getInstance();
    }

    /**
     * 根据扩展名返回预览类型字符串。
     */
    public function detectType(string $ext): string
    {
        $ext = strtolower($ext);
        if (in_array($ext, self::IMAGE_TYPES, true)) return self::TYPE_IMAGE;
        if (in_array($ext, self::VIDEO_TYPES, true)) return self::TYPE_VIDEO;
        if (in_array($ext, self::AUDIO_TYPES, true)) return self::TYPE_AUDIO;
        if (in_array($ext, self::MARKDOWN_TYPES, true)) return self::TYPE_MARKDOWN;
        if (in_array($ext, self::TEXT_TYPES, true)) return self::TYPE_TEXT;
        if (in_array($ext, self::CSV_TYPES, true)) return self::TYPE_CSV;
        if (in_array($ext, self::PDF_TYPES, true)) return self::TYPE_PDF;
        if (in_array($ext, self::ZIP_TYPES, true)) return self::TYPE_ZIP;
        if (in_array($ext, self::EXCEL_TYPES, true)) return self::TYPE_EXCEL;
        if (in_array($ext, self::WORD_TYPES, true)) return self::TYPE_WORD;
        return self::TYPE_UNKNOWN;
    }

    /**
     * 根据 previewType 取对应的最大预览尺寸阈值（字节）。
     * 未识别类型回退到 preview_max_size 配置或默认 150MB。
     */
    public function getSizeLimit(string $previewType): int
    {
        $limits = [
            self::TYPE_IMAGE    => $this->config->get('preview_max_size_image', 10485760),
            self::TYPE_VIDEO    => $this->config->get('preview_max_size_media', 157286400),
            self::TYPE_AUDIO    => $this->config->get('preview_max_size_media', 157286400),
            self::TYPE_MARKDOWN => $this->config->get('preview_max_size_text', 1048576),
            self::TYPE_TEXT     => $this->config->get('preview_max_size_text', 1048576),
            self::TYPE_CSV      => $this->config->get('preview_max_size_text', 1048576),
            self::TYPE_PDF      => $this->config->get('preview_max_size_pdf', 52428800),
            self::TYPE_ZIP      => $this->config->get('preview_max_size_zip', 104857600),
            self::TYPE_EXCEL    => $this->config->get('preview_max_size_office', 52428800),
            self::TYPE_WORD     => $this->config->get('preview_max_size_office', 52428800),
        ];
        return $limits[$previewType] ?? $this->config->get('preview_max_size', 157286400);
    }

    /**
     * 读取文本类（text/markdown/csv）预览内容。
     * 仅读前 500KB，自动检测编码并转换为 UTF-8。
     *
     * @return array{success:bool, content?:string, message?:string}
     */
    public function readTextContent(string $fullPath): array
    {
        $fp = @fopen($fullPath, 'rb');
        if ($fp === false) {
            return ['success' => false, 'message' => '无法读取文件内容'];
        }
        $content = fread($fp, self::TEXT_READ_LIMIT);
        fclose($fp);
        if ($content === false || $content === '') {
            return ['success' => false, 'message' => '无法读取文件内容'];
        }
        $detectedEncoding = mb_detect_encoding($content, self::TEXT_ENCODING_DETECT_ORDER, true);
        if ($detectedEncoding === false) {
            $detectedEncoding = 'UTF-8';
        }
        $sanitized = mb_convert_encoding($content, 'UTF-8', $detectedEncoding);
        return ['success' => true, 'content' => $sanitized];
    }

    /**
     * 将 ZIP 内条目名转换为 UTF-8。
     * Windows 下创建的 zip 文件名常为 GBK/GB2312 编码，直接输出会导致中文乱码。
     * 优先尊重已是 UTF-8 的原文，其次按常见中文编码探测后转换，最后兜底按 GBK 转换。
     */
    public function convertZipEntryName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        if ($name === '' || mb_check_encoding($name, 'UTF-8')) {
            return $name;
        }
        $detected = mb_detect_encoding($name, self::TEXT_ENCODING_DETECT_ORDER, true);
        if ($detected !== false && strtoupper($detected) !== 'UTF-8') {
            $converted = @mb_convert_encoding($name, 'UTF-8', $detected);
            if ($converted !== '' && $converted !== false) {
                return $converted;
            }
        }
        // 兜底：Windows 中文 zip 最常见为 GBK
        $converted = @mb_convert_encoding($name, 'UTF-8', 'GBK');
        if ($converted !== '' && $converted !== false) {
            return $converted;
        }
        return $name;
    }

    /**
     * 判断扩展名是否为音频类型。
     * 供缩略图流程复用，避免 audioTypes 数组在多处重复定义。
     */
    public function isAudioType(string $ext): bool
    {
        return in_array(strtolower($ext), self::AUDIO_TYPES, true);
    }

    /**
     * 预览资源浏览器缓存时长（秒）。
     * 图片/PDF 7 天（内容通过 URL 参数版本化，可安全长期缓存），其他媒体 1 小时。
     */
    public function getCacheMaxAge(string $previewType): int
    {
        if (in_array($previewType, [self::TYPE_IMAGE, self::TYPE_PDF], true)) {
            return 604800;
        }
        return 3600;
    }
}
