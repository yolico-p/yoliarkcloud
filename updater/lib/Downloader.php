<?php

namespace Updater;

/**
 * 下载器。
 *
 * 稳定性设计（对照 update-server-guide.md 第 10 节）：
 * - 强制 HTTPS。
 * - 断点续传：HTTP Range 请求，206 追加 / 200 截断重写 / 416 交由 SHA256 校验。
 * - 指数退避重试：1s → 2s → 4s，最多 maxRetries 次。
 * - SHA256 校验：下载完成后强制校验，失败按断点续传策略重试。
 *
 * 关键修复：当本地已有部分文件而服务器返回 200（不支持 Range）时，
 * 必须截断重写而非追加，否则文件会损坏。
 */
class Downloader
{
    private int $maxRetries;
    private int $timeout;

    public function __construct(?int $maxRetries = null, ?int $timeout = null)
    {
        $config = require ROOT_PATH . DIRECTORY_SEPARATOR . 'updater' . DIRECTORY_SEPARATOR . 'config.php';
        $this->maxRetries = $maxRetries ?? (int)($config['download_retry'] ?? 3);
        $this->timeout    = $timeout ?? (int)($config['download_timeout'] ?? 300);
    }

    /**
     * 下载文件。支持断点续传，下载完成后做 SHA256 校验。
     *
     * @param string $url            下载地址（必须 HTTPS）
     * @param string $destPath       目标路径
     * @param string $expectedSha256 期望的 SHA256（小写十六进制）
     * @param int    $packageSize    包大小（字节，可选，用于判断是否已完整以决定续传策略）
     * @return bool 成功返回 true，失败返回 false
     */
    public function download(string $url, string $destPath, string $expectedSha256, int $packageSize = 0): bool
    {
        $this->ensureHttps($url);

        if (!is_dir(dirname($destPath))) {
            @mkdir(dirname($destPath), 0755, true);
        }

        $expected = strtolower($expectedSha256);
        $attempt  = 0;
        $backoff  = 1;

        while ($attempt < $this->maxRetries) {
            $this->downloadOnce($url, $destPath);

            $actual = is_file($destPath) ? hash_file('sha256', $destPath) : false;
            if ($actual !== false && hash_equals($expected, strtolower($actual))) {
                return true;
            }

            // SHA256 失败：决定是否保留断点续传
            $currentSize = is_file($destPath) ? (int)filesize($destPath) : 0;
            if ($packageSize > 0 && $currentSize >= $packageSize) {
                // 文件已完整却校验失败 → 损坏，删除重下
                @unlink($destPath);
            }
            // 否则保留已下载部分，下一次尝试断点续传

            $attempt++;
            if ($attempt < $this->maxRetries) {
                sleep($backoff);
                $backoff *= 2;
            }
        }

        return false;
    }

    /**
     * 单次下载尝试（断点续传）。
     *
     * 通过 WRITEFUNCTION 回调根据响应码决定写入模式：
     * - 206 Partial Content：追加模式（ab），续传
     * - 200 OK：截断重写模式（wb），服务器不支持 Range
     * - 416 Range Not Satisfiable：丢弃 body（文件可能已完整），交由 SHA256 校验
     *
     * @return bool curl_exec 是否成功（不代表下载完整，由调用方校验 SHA256）
     */
    private function downloadOnce(string $url, string $destPath): bool
    {
        $existingSize = is_file($destPath) ? (int)filesize($destPath) : 0;

        $ctx = [
            'fp'   => null,
            'code' => 0,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => self::userAgent(),
            CURLOPT_FAILONERROR    => false,
            CURLOPT_HEADER         => false,
            CURLOPT_WRITEFUNCTION  => static function ($curl, $data) use (&$ctx, $destPath) {
                if ($ctx['fp'] === null) {
                    $ctx['code'] = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                    if ($ctx['code'] === 206) {
                        // 断点续传：追加
                        $ctx['fp'] = @fopen($destPath, 'ab');
                    } elseif ($ctx['code'] === 416) {
                        // 文件可能已完整：丢弃 body，不打开文件
                        return strlen($data);
                    } else {
                        // 200 或其他：截断重写
                        $ctx['fp'] = @fopen($destPath, 'wb');
                    }
                    if (!$ctx['fp']) {
                        return -1;
                    }
                }
                $written = fwrite($ctx['fp'], $data);
                return $written === false ? -1 : $written;
            },
        ]);

        if ($existingSize > 0) {
            curl_setopt($ch, CURLOPT_RANGE, $existingSize . '-');
        }

        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);

        if (is_resource($ctx['fp'])) {
            @fflush($ctx['fp']);
            fclose($ctx['fp']);
        }

        if ($ok === false) {
            return false;
        }

        // 200 / 206 / 416 都视为可能完成（最终由 SHA256 仲裁）
        return in_array($code, [200, 206, 416], true);
    }

    private function ensureHttps(string $url): void
    {
        $parsed = parse_url($url);
        if (!isset($parsed['scheme']) || strtolower($parsed['scheme']) !== 'https') {
            throw new \RuntimeException('Download URL must use HTTPS: ' . $url);
        }
    }

    /**
     * 标准化 User-Agent（update-server-guide.md 附录 C）。
     */
    private static function userAgent(): string
    {
        $ver = defined('PANCLOUD_VERSION') ? PANCLOUD_VERSION : '0.0.0';
        $os  = PHP_OS_FAMILY;
        $php = PHP_VERSION;
        $instanceHash = '';
        try {
            $instanceHash = substr(Manifest::getInstanceId(), 0, 8);
        } catch (\Throwable $e) {
            // 忽略
        }
        return 'YoliArkCloud-Updater/' . $ver . ' (' . $os . '; PHP ' . $php . '; Instance ' . $instanceHash . ')';
    }
}
