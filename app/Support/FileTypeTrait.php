<?php

namespace App\Support;

/**
 * 文件类型识别与 MIME 检测的辅助方法。
 *
 * 抽出自 FileManagerService，便于在多个 Service / Controller 间复用。
 * 宿主类需保证不重复定义同名的 private 属性 fileTypeCache。
 */
trait FileTypeTrait
{
    /** 文件类型缓存（基于文件名 + 内容哈希） */
    private $fileTypeCache = [];

    private static function getFileTypeMap()
    {
        static $map = null;
        if ($map === null) {
            $map = [
                'folder' => 'folder',
                'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'bmp' => 'image', 'webp' => 'image', 'svg' => 'image', 'ico' => 'image', 'tiff' => 'image', 'tif' => 'image', 'raw' => 'image', 'cr2' => 'image', 'nef' => 'image', 'arw' => 'image', 'psd' => 'image', 'ai' => 'image', 'sketch' => 'image', 'fig' => 'image', 'xcf' => 'image', 'heic' => 'image', 'heif' => 'image', 'avif' => 'image',
                'mp4' => 'video', 'avi' => 'video', 'mkv' => 'video', 'mov' => 'video', 'wmv' => 'video', 'flv' => 'video', 'webm' => 'video', '3gp' => 'video', 'm4v' => 'video', 'mpg' => 'video', 'mpeg' => 'video', 'ts' => 'video', 'f4v' => 'video', 'ogv' => 'video', 'rm' => 'video', 'rmvb' => 'video', 'vob' => 'video', 'mts' => 'video', 'm2ts' => 'video',
                'mp3' => 'audio', 'wav' => 'audio', 'flac' => 'audio', 'aac' => 'audio', 'ogg' => 'audio', 'wma' => 'audio', 'aiff' => 'audio', 'aif' => 'audio', 'm4a' => 'audio', 'opus' => 'audio', 'ape' => 'audio', 'alac' => 'audio', 'ra' => 'audio', 'ram' => 'audio', 'ac3' => 'audio', 'amr' => 'audio', 'mid' => 'audio', 'midi' => 'audio',
                'pdf' => 'pdf',
                'doc' => 'word', 'docx' => 'word', 'odt' => 'word', 'rtf' => 'word', 'pages' => 'word',
                'xls' => 'excel', 'xlsx' => 'excel', 'ods' => 'excel', 'numbers' => 'excel', 'xlsm' => 'excel',
                'ppt' => 'ppt', 'pptx' => 'ppt', 'odp' => 'ppt', 'key' => 'ppt',
                'txt' => 'text', 'md' => 'text', 'csv' => 'text', 'log' => 'text', 'ini' => 'text', 'cfg' => 'text', 'conf' => 'text', 'srt' => 'text', 'ass' => 'text', 'ssa' => 'text', 'vtt' => 'text', 'nfo' => 'text',
                'json' => 'code', 'xml' => 'code', 'html' => 'code', 'htm' => 'code', 'css' => 'code', 'js' => 'code', 'ts' => 'code', 'jsx' => 'code', 'tsx' => 'code', 'vue' => 'code', 'py' => 'code', 'rb' => 'code', 'java' => 'code', 'c' => 'code', 'cpp' => 'code', 'h' => 'code', 'hpp' => 'code', 'go' => 'code', 'rs' => 'code', 'sql' => 'code', 'sh' => 'code', 'bash' => 'code', 'bat' => 'code', 'ps1' => 'code', 'r' => 'code', 'm' => 'code', 'swift' => 'code', 'kt' => 'code', 'scala' => 'code', 'php' => 'code', 'lua' => 'code', 'pl' => 'code', 'pm' => 'code', 'dart' => 'code', 'yaml' => 'code', 'yml' => 'code', 'toml' => 'code', 'env' => 'code', 'gitignore' => 'code', 'dockerfile' => 'code', 'mdx' => 'code', 'svelte' => 'code', 'astro' => 'code',
                'zip' => 'archive', 'rar' => 'archive', '7z' => 'archive', 'tar' => 'archive', 'gz' => 'archive', 'bz2' => 'archive', 'xz' => 'archive', 'zst' => 'archive', 'cab' => 'archive', 'iso' => 'archive', 'dmg' => 'archive', 'img' => 'archive', 'lz4' => 'archive',
                'exe' => 'app', 'msi' => 'app', 'deb' => 'app', 'rpm' => 'app', 'apk' => 'app', 'appimage' => 'app', 'pkg' => 'app',
                'ttf' => 'font', 'otf' => 'font', 'woff' => 'font', 'woff2' => 'font', 'eot' => 'font',
                'epub' => 'book', 'mobi' => 'book', 'azw3' => 'book', 'fb2' => 'book', 'cbz' => 'book', 'cbr' => 'book', 'djvu' => 'book',
                'torrent' => 'archive',
                '3ds' => 'archive', 'obj' => 'archive', 'stl' => 'archive', 'fbx' => 'archive', 'blend' => 'archive', 'gltf' => 'archive',
            ];
        }
        return $map;
    }

    /**
     * 获取文件类型分组（用于图标显示）。
     * 接受文件数组（含 is_dir / file_type）或纯扩展名字符串。
     */
    private function getFileIcon($file)
    {
        if (is_array($file)) {
            if (!empty($file['is_dir'])) return 'folder';
            $ext = strtolower($file['file_type'] ?? '');
        } else {
            $ext = strtolower((string)$file);
        }
        return self::getFileTypeMap()[$ext] ?? 'file';
    }

    private function hasThumbnailSupport($fileType)
    {
        static $supported = null;
        if ($supported === null) {
            $supported = [
                'jpg' => 1, 'jpeg' => 1, 'png' => 1, 'gif' => 1, 'bmp' => 1, 'webp' => 1, 'svg' => 1, 'ico' => 1, 'tiff' => 1, 'tif' => 1,
                'mp3' => 1, 'wav' => 1, 'flac' => 1, 'aac' => 1, 'ogg' => 1, 'wma' => 1, 'm4a' => 1, 'aiff' => 1, 'aif' => 1, 'opus' => 1, 'ape' => 1, 'alac' => 1, 'ra' => 1, 'ram' => 1, 'ac3' => 1, 'amr' => 1, 'mid' => 1, 'midi' => 1,
            ];
        }
        return isset($supported[strtolower($fileType)]);
    }

    /**
     * 是否安全文件类型（用于决定是否跳过分片内容扫描）。
     * 结果按 文件名+前8KB内容哈希 缓存。
     */
    private function isSafeFileType($filename, $filePath = null)
    {
        $cacheKey = $filename;
        if ($filePath && file_exists($filePath)) {
            $contentHash = hash_file('sha256', $filePath, false);
            if ($contentHash) {
                $cacheKey = $filename . '_' . substr($contentHash, 0, 16);
            }
        }

        if (isset($this->fileTypeCache[$cacheKey])) {
            return $this->fileTypeCache[$cacheKey];
        }

        static $safeExtensions = null;
        if ($safeExtensions === null) {
            $safeExtensions = array_flip([
                'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'tif',
                'mp3', 'wav', 'flac', 'aac', 'ogg', 'wma', 'm4a', 'mid', 'midi', 'aiff', 'aif', 'opus', 'ape', 'alac', 'ra', 'ram', 'ac3', 'amr',
                'mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm', 'rmvb', 'rm', '3gp', 'm4v', 'mpg', 'mpeg', 'ts', 'f4v', 'ogv', 'vob', 'mts', 'm2ts',
                'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz',
                'ttf', 'otf', 'woff', 'woff2', 'eot',
                'pdf',
            ]);
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $result = isset($safeExtensions[$ext]);
        $this->fileTypeCache[$cacheKey] = $result;
        return $result;
    }

    private static function getMimeTypeMap()
    {
        static $map = null;
        if ($map === null) {
            $map = [
                // Images
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'bmp' => 'image/bmp',
                'webp' => 'image/webp',
                'svg' => 'image/svg+xml',
                'ico' => 'image/x-icon',
                'tiff' => 'image/tiff',
                'tif' => 'image/tiff',
                'raw' => 'image/x-raw',
                'cr2' => 'image/x-canon-cr2',
                'nef' => 'image/x-nikon-nef',
                'arw' => 'image/x-sony-arw',
                'psd' => 'image/vnd.adobe.photoshop',
                'ai' => 'application/postscript',
                'heic' => 'image/heic',
                'heif' => 'image/heif',
                'avif' => 'image/avif',

                // Videos
                'mp4' => 'video/mp4',
                'avi' => 'video/x-msvideo',
                'mkv' => 'video/x-matroska',
                'mov' => 'video/quicktime',
                'wmv' => 'video/x-ms-wmv',
                'flv' => 'video/x-flv',
                'webm' => 'video/webm',
                '3gp' => 'video/3gpp',
                'm4v' => 'video/mp4',
                'mpg' => 'video/mpeg',
                'mpeg' => 'video/mpeg',
                'ts' => 'video/mp2t',
                'f4v' => 'video/mp4',
                'ogv' => 'video/ogg',
                'rm' => 'application/vnd.rn-realmedia',
                'rmvb' => 'application/vnd.rn-realmedia-vbr',
                'vob' => 'video/dvd',
                'mts' => 'video/mp2t',
                'm2ts' => 'video/mp2t',

                // Audio
                'mp3' => 'audio/mpeg',
                'wav' => 'audio/wav',
                'flac' => 'audio/flac',
                'aac' => 'audio/aac',
                'ogg' => 'audio/ogg',
                'wma' => 'audio/x-ms-wma',
                'aiff' => 'audio/aiff',
                'aif' => 'audio/aiff',
                'm4a' => 'audio/mp4',
                'opus' => 'audio/opus',
                'ape' => 'audio/ape',
                'alac' => 'audio/mp4',
                'ra' => 'audio/vnd.rn-realaudio',
                'ram' => 'audio/vnd.rn-realaudio',
                'ac3' => 'audio/ac3',
                'amr' => 'audio/amr',
                'mid' => 'audio/midi',
                'midi' => 'audio/midi',

                // PDF
                'pdf' => 'application/pdf',

                // Office
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
                'ppt' => 'application/vnd.ms-powerpoint',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'odt' => 'application/vnd.oasis.opendocument.text',
                'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
                'odp' => 'application/vnd.oasis.opendocument.presentation',
                'rtf' => 'application/rtf',
                'pages' => 'application/vnd.apple.pages',
                'numbers' => 'application/vnd.apple.numbers',
                'key' => 'application/vnd.apple.keynote',

                // Text / Code
                'txt' => 'text/plain',
                'md' => 'text/markdown',
                'csv' => 'text/csv',
                'log' => 'text/plain',
                'ini' => 'text/plain',
                'cfg' => 'text/plain',
                'conf' => 'text/plain',
                'srt' => 'application/x-subrip',
                'ass' => 'text/x-ssa',
                'ssa' => 'text/x-ssa',
                'vtt' => 'text/vtt',
                'nfo' => 'text/plain',
                'json' => 'application/json',
                'xml' => 'application/xml',
                'html' => 'text/html',
                'htm' => 'text/html',
                'css' => 'text/css',
                'js' => 'application/javascript',
                'ts' => 'application/typescript',
                'jsx' => 'application/javascript',
                'tsx' => 'application/typescript',
                'vue' => 'text/plain',
                'py' => 'text/x-python',
                'rb' => 'text/x-ruby',
                'java' => 'text/x-java-source',
                'c' => 'text/x-c',
                'cpp' => 'text/x-c++',
                'h' => 'text/x-c',
                'hpp' => 'text/x-c++',
                'go' => 'text/x-go',
                'rs' => 'text/x-rust',
                'sql' => 'application/sql',
                'sh' => 'application/x-sh',
                'bash' => 'application/x-sh',
                'bat' => 'application/bat',
                'ps1' => 'application/powershell',
                'r' => 'text/x-r',
                'm' => 'text/plain',
                'swift' => 'text/x-swift',
                'kt' => 'text/x-kotlin',
                'scala' => 'text/x-scala',
                'php' => 'application/x-httpd-php',
                'lua' => 'text/x-lua',
                'pl' => 'application/x-perl',
                'pm' => 'application/x-perl',
                'dart' => 'application/vnd.dart',
                'yaml' => 'text/yaml',
                'yml' => 'text/yaml',
                'toml' => 'text/toml',
                'env' => 'text/plain',
                'mdx' => 'text/markdown',
                'svelte' => 'text/plain',
                'astro' => 'text/plain',

                // Archives
                'zip' => 'application/zip',
                'rar' => 'application/vnd.rar',
                '7z' => 'application/x-7z-compressed',
                'tar' => 'application/x-tar',
                'gz' => 'application/gzip',
                'bz2' => 'application/x-bzip2',
                'xz' => 'application/x-xz',
                'zst' => 'application/zstd',
                'cab' => 'application/vnd.ms-cab-compressed',
                'iso' => 'application/x-iso9660-image',
                'dmg' => 'application/x-apple-diskimage',
                'img' => 'application/octet-stream',
                'lz4' => 'application/x-lz4',
                'torrent' => 'application/x-bittorrent',

                // Applications / Fonts / Books / 3D
                'exe' => 'application/x-msdownload',
                'msi' => 'application/x-msdownload',
                'deb' => 'application/vnd.debian.binary-package',
                'rpm' => 'application/x-rpm',
                'apk' => 'application/vnd.android.package-archive',
                'appimage' => 'application/x-executable',
                'pkg' => 'application/octet-stream',
                'ttf' => 'font/ttf',
                'otf' => 'font/otf',
                'woff' => 'font/woff',
                'woff2' => 'font/woff2',
                'eot' => 'application/vnd.ms-fontobject',
                'epub' => 'application/epub+zip',
                'mobi' => 'application/x-mobipocket-ebook',
                'azw3' => 'application/vnd.amazon.ebook',
                'fb2' => 'application/xml',
                'cbz' => 'application/x-cbr',
                'cbr' => 'application/x-cbr',
                'djvu' => 'image/vnd.djvu',
                '3ds' => 'application/octet-stream',
                'obj' => 'application/octet-stream',
                'stl' => 'model/stl',
                'fbx' => 'application/octet-stream',
                'blend' => 'application/octet-stream',
                'gltf' => 'model/gltf+json',
            ];
        }
        return $map;
    }

    private function getMimeType($filePath)
    {
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($filePath);
            if ($mime !== false) return $mime;
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                if ($mime) return $mime;
            }
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return self::getMimeTypeMap()[$ext] ?? 'application/octet-stream';
    }

    /**
     * 流式计算 SHA-256（避免大文件一次性加载到内存）。
     */
    private function calculateSHA256($filePath)
    {
        $ctx = hash_init('sha256');
        $fp = fopen($filePath, 'rb');
        if (!$fp) {
            return hash_file('sha256', $filePath);
        }
        while (!feof($fp)) {
            $chunk = fread($fp, 65536);
            if ($chunk === false) break;
            hash_update($ctx, $chunk);
        }
        fclose($fp);
        return hash_final($ctx);
    }
}
