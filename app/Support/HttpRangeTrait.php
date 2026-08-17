<?php

namespace App\Support;

/**
 * HTTP Range 请求处理 Trait
 *
 * 提供支持断点续传 / 分段下载的文件输出方法，
 * 供 DownloadController、SharePublicController 等复用，
 * 避免在各 Controller 中重复实现 Range 解析与 206 响应逻辑。
 */
trait HttpRangeTrait
{
    /**
     * 发送文件并支持 HTTP Range 请求（断点续传 / 分段下载）。
     *
     * 该方法会：
     * 1. 清除输出缓冲，避免二进制流经过压缩层；
     * 2. 设置 Content-Type、Content-Length、Accept-Ranges、Content-Disposition 等头；
     * 3. 解析 Range 请求头，返回 206 Partial Content 或 416 Range Not Satisfiable；
     * 4. 分段读取文件并输出；
     * 5. 若 $isTemp 为 true，输出完成后删除临时文件；
     * 6. 调用 exit 终止脚本。
     *
     * 注意：调用方应在完成所有权限校验、路径穿越防护后再调用本方法。
     *
     * @param string      $filePath     文件绝对路径
     * @param string|null $filename     下载文件名；传 null 表示不设置 Content-Disposition（用于在线预览）
     * @param int         $fileSize     文件字节数
     * @param string      $mimeType     MIME 类型
     * @param string      $contentHash  可选的 SHA-256 哈希（通过 X-Content-SHA256 头返回）
     * @param bool        $isTemp       是否为临时文件，true 时输出完成后会删除原文件
     * @param string|null $disposition  Content-Disposition 模式：'inline'、'attachment' 或 null（null 时按 attachment 处理）
     * @return void
     */
    protected function sendFileWithRange(
        string $filePath,
        ?string $filename,
        int $fileSize,
        string $mimeType = 'application/octet-stream',
        string $contentHash = '',
        bool $isTemp = false,
        ?string $disposition = null
    ): void {
        ignore_user_abort(true);
        set_time_limit(3600);
        // 清除输出压缩缓冲，防止二进制内容被 gzip/brotli 改变长度
        \App\Controllers\BaseController::cleanOutputBuffer();

        if (!file_exists($filePath)) {
            http_response_code(404);
            exit('文件不存在');
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . $fileSize);
        header('Accept-Ranges: bytes');

        if (!empty($contentHash)) {
            header('X-Content-SHA256: ' . $contentHash);
        }

        if ($filename !== null) {
            $mode = $disposition === 'inline' ? 'inline' : 'attachment';
            header('Content-Disposition: ' . $mode . '; filename="' . \App\Core\Security::escape($filename) . '"');
        }

        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if (!empty($range) && preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
            $start = intval($matches[1]);
            $end = !empty($matches[2]) ? intval($matches[2]) : $fileSize - 1;

            // 范围非法时返回 416，避免向客户端输出空响应体
            if ($start > $end || $start >= $fileSize) {
                http_response_code(416);
                header('Content-Range: bytes */' . $fileSize);
                exit;
            }

            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
            header('Content-Length: ' . ($end - $start + 1));

            $fp = fopen($filePath, 'rb');
            if ($fp !== false) {
                fseek($fp, $start);
                $remaining = $end - $start + 1;
                while ($remaining > 0 && !feof($fp)) {
                    $chunk = fread($fp, min(65536, $remaining));
                    echo $chunk;
                    $remaining -= strlen($chunk);
                }
                fclose($fp);
            }
        } else {
            $fp = fopen($filePath, 'rb');
            if ($fp !== false) {
                while (!feof($fp)) {
                    echo fread($fp, 65536);
                    flush();
                }
                fclose($fp);
            }
        }

        if ($isTemp) {
            @unlink($filePath);
        }

        exit;
    }
}
