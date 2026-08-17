<?php

namespace App\Core;

class AsyncLogger
{
    private static $instance = null;
    private $logBuffer = [];
    private $bufferSize = 50;
    private $logFile;
    private $asyncMode = true;

    /** 日志轮转：最大文件大小（字节），默认 10MB */
    private int $maxSize = 10485760;
    /** 日志轮转：保留的旧日志文件数量 */
    private int $maxFiles = 10;
    /** 每写入 N 条日志后检查一次轮转，避免每次写入都 fstat */
    private int $rotateCheckInterval = 100;
    /** 写入计数器，达到 rotateCheckInterval 后重置并检查轮转 */
    private int $writeCounter = 0;

    private function __construct()
    {
        $this->logFile = STORAGE_PATH . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        register_shutdown_function([$this, 'flush']);
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    public function setAsyncMode($enabled)
    {
        $this->asyncMode = $enabled;
    }

    public function log($message, $level = 'info', $context = [])
    {
        $entry = [
            'timestamp' => microtime(true),
            'datetime' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'user_id' => $_SESSION['user_id'] ?? 0,
            'ip' => Security::getClientIP(),
            'memory' => memory_get_usage(true),
        ];

        if ($this->asyncMode) {
            $this->logBuffer[] = $entry;

            if (count($this->logBuffer) >= $this->bufferSize) {
                $this->flush();
            }
        } else {
            $this->writeLog($entry);
        }
    }

    public function info($message, $context = [])
    {
        $this->log($message, 'info', $context);
    }

    public function warning($message, $context = [])
    {
        $this->log($message, 'warning', $context);
    }

    public function error($message, $context = [])
    {
        $this->log($message, 'error', $context);
    }

    public function debug($message, $context = [])
    {
        if (defined('DEBUG') && DEBUG) {
            $this->log($message, 'debug', $context);
        }
    }

    public function flush()
    {
        if (empty($this->logBuffer)) {
            return;
        }

        $logsToWrite = $this->logBuffer;
        $this->logBuffer = [];

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // 批量格式化所有日志条目，单次 fwrite 写入，减少 fopen/flock 开销
        $formatted = '';
        foreach ($logsToWrite as $entry) {
            $formatted .= sprintf(
                "[%s] [%s] [UID:%d] [IP:%s] [MEM:%d] %s %s\n",
                $entry['datetime'],
                strtoupper($entry['level']),
                $entry['user_id'],
                $entry['ip'],
                $entry['memory'],
                $entry['message'],
                !empty($entry['context']) ? json_encode($entry['context'], JSON_UNESCAPED_UNICODE) : ''
            );
        }

        $fp = fopen($this->logFile, 'a');
        if ($fp) {
            if (flock($fp, LOCK_EX)) {
                try {
                    fwrite($fp, $formatted);
                    fflush($fp);
                    // 批量写入后累加计数器，达到阈值时在锁内检查轮转
                    $this->writeCounter += count($logsToWrite);
                    if ($this->writeCounter >= $this->rotateCheckInterval) {
                        $this->writeCounter = 0;
                        $this->rotateLogsLocked($fp);
                    }
                } finally {
                    flock($fp, LOCK_UN);
                }
            }
            fclose($fp);
        }
    }

    /**
     * 写入单条日志并在锁内检查轮转。
     *
     * 修复：原实现 rotateLogs() 在 flock 外执行，rename 可能移动
     * 正在被写入的文件，导致日志丢失或写入到已轮转的文件。
     * 现在将轮转检查移入 flock 保护范围，并降低检查频率（每 N 次写入检查一次）。
     */
    private function writeLog($entry)
    {
        $formatted = sprintf(
            "[%s] [%s] [UID:%d] [IP:%s] [MEM:%d] %s %s\n",
            $entry['datetime'],
            strtoupper($entry['level']),
            $entry['user_id'],
            $entry['ip'],
            $entry['memory'],
            $entry['message'],
            !empty($entry['context']) ? json_encode($entry['context'], JSON_UNESCAPED_UNICODE) : ''
        );

        $fp = fopen($this->logFile, 'a');
        if ($fp) {
            if (flock($fp, LOCK_EX)) {
                try {
                    fwrite($fp, $formatted);
                    fflush($fp);
                    // 在锁内检查并执行轮转，避免 rename 竞态
                    $this->writeCounter++;
                    if ($this->writeCounter >= $this->rotateCheckInterval) {
                        $this->writeCounter = 0;
                        $this->rotateLogsLocked($fp);
                    }
                } finally {
                    flock($fp, LOCK_UN);
                }
            }
            fclose($fp);
        }
    }

    /**
     * 在已持有 flock 的情况下执行日志轮转。
     *
     * 通过 fstat($fp) 获取文件大小（而非 filesize()，避免 TOCTOU），
     * 若超过 maxSize 则 rename 当前文件为带时间戳的归档文件。
     * rename 时文件已打开且持锁，Linux 下 rename 不影响已打开的 fd，
     * 下次 fopen($this->logFile, 'a') 会创建新文件。
     *
     * @param resource $fp 已持有 LOCK_EX 的文件句柄
     */
    private function rotateLogsLocked($fp)
    {
        $stat = fstat($fp);
        if ($stat['size'] <= $this->maxSize) {
            return;
        }

        $timestamp = date('Ymd_His');
        $rotatedFile = dirname($this->logFile) . DIRECTORY_SEPARATOR . 'app_' . $timestamp . '.log';

        // rename 时文件已打开且持锁，不影响已打开的 fd，但新 fopen 会创建新文件
        rename($this->logFile, $rotatedFile);

        // 可选：压缩旧日志以节省磁盘空间
        if (file_exists($rotatedFile) && function_exists('gzencode')) {
            $content = @file_get_contents($rotatedFile);
            if ($content !== false) {
                @file_put_contents($rotatedFile . '.gz', gzencode($content));
                @unlink($rotatedFile);
            }
        }

        $this->cleanupOldLogs($this->maxFiles);
    }

    private function cleanupOldLogs($maxFiles)
    {
        $logDir = dirname($this->logFile);
        // 同时清理未压缩和已压缩的旧日志
        $logFiles = array_merge(
            glob($logDir . DIRECTORY_SEPARATOR . 'app_*.log') ?: [],
            glob($logDir . DIRECTORY_SEPARATOR . 'app_*.log.gz') ?: []
        );

        if (count($logFiles) > $maxFiles) {
            usort($logFiles, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });

            $toDelete = array_slice($logFiles, $maxFiles);
            foreach ($toDelete as $file) {
                @unlink($file);
            }
        }
    }

    public function getRecentLogs($lines = 100)
    {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $file = new \SplFileObject($this->logFile);
        $file->seek(PHP_INT_MAX);
        $total = $file->key();

        $start = max(0, $total - $lines);
        $logs = [];

        for ($i = $start; $i < $total; $i++) {
            $file->seek($i);
            $line = $file->current();
            if ($line) {
                $logs[] = $line;
            }
        }

        return array_reverse($logs);
    }

    public function getLogStats()
    {
        $stats = [
            'total_logs' => 0,
            'file_size' => 0,
            'buffer_size' => count($this->logBuffer),
            'by_level' => [
                'info' => 0,
                'warning' => 0,
                'error' => 0,
                'debug' => 0,
            ],
        ];

        if (!file_exists($this->logFile)) {
            return $stats;
        }

        $stats['file_size'] = filesize($this->logFile);

        // 修复：原实现 file_get_contents 全量加载 + substr_count 统计，大日志会 OOM
        // 改为流式逐行扫描 + 早期终止（达到 maxLines 即停）
        $maxLines = 100000;
        $levelMap = [
            'INFO' => 'info',
            'WARNING' => 'warning',
            'ERROR' => 'error',
            'DEBUG' => 'debug',
        ];

        $fp = @fopen($this->logFile, 'r');
        if (!$fp) {
            return $stats;
        }

        $count = 0;
        while (($line = fgets($fp)) !== false && $count < $maxLines) {
            $stats['total_logs']++;
            $count++;
            // 行格式：[datetime] [LEVEL] [UID:..] [IP:..] [MEM:..] msg context
            if (preg_match('/\[(INFO|WARNING|ERROR|DEBUG)\]/', $line, $m)) {
                $levelKey = $levelMap[$m[1]] ?? null;
                if ($levelKey !== null) {
                    $stats['by_level'][$levelKey]++;
                }
            }
        }
        fclose($fp);

        return $stats;
    }
}
