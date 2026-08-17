<?php

namespace App\Core;

use App\Core\Database;

/**
 * AI 对话流式进度文件存储。
 *
 * 用途：作为 CLI Worker 与 FPM SSE 端点之间的文件读写中介。
 * - Worker 执行任务时把流式事件（token / tool_start / done 等）通过 {@see appendEvent()} 追加写入文件
 * - FPM 端 SSE 端点通过 {@see readEvents()} 读取增量事件并转发给前端
 * - {@see getTaskStatus()} 从 `ai_agent_progress` 表读取任务粗粒度状态
 *
 * 存储位置：`DATA_PATH . '/stream/'`，每个任务一个 `{taskId}.sse` 文件。
 * 行格式：`{seq}\t{type}\t{json}\n`，seq 从 1 开始单调递增。
 *
 * 错误处理：文件操作失败不抛异常，返回空数组 / 0 / unknown，由 Worker 重试机制兜底。
 */
class StreamProgressStore
{
    /** @var string stream 文件目录 */
    private string $streamDir;

    public function __construct()
    {
        $this->streamDir = DATA_PATH . '/stream';
    }

    // ========================================================================
    //  目录与路径
    // ========================================================================

    /**
     * 确保 stream 目录存在，不存在则递归创建。
     */
    private function ensureDir(): void
    {
        if (!is_dir($this->streamDir)) {
            @mkdir($this->streamDir, 0755, true);
        }
    }

    /**
     * 根据任务 ID 生成对应的 stream 文件路径。
     *
     * taskId 可能含非文件名安全字符，用 preg_replace 清理为下划线，
     * 防止路径穿越与非法文件名。
     *
     * @param string $taskId 任务 ID
     * @return string 文件绝对路径
     */
    private function filePath(string $taskId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $taskId);
        return $this->streamDir . '/' . $safe . '.sse';
    }

    /**
     * sidecar seq 文件路径，存储当前最大 seq。
     * 与 sse 文件一一对应，避免每次 appendEvent 读全文件计数。
     */
    private function seqFilePath(string $taskId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $taskId);
        return $this->streamDir . '/' . $safe . '.seq';
    }

    // ========================================================================
    //  事件读写
    // ========================================================================

    /**
     * 原子追加一个流式事件到任务文件，返回事件序列号。
     *
     * 实现要点：
     * 1. 通过 sidecar `.seq` 文件原子 read-modify-write 获取下一个 seq，
     *    避免每次读取整个 sse 文件计数（O(n²) 优化）
     * 2. sse 文件以 `a` 模式追加写入（无需锁，因为 seq 已由 sidecar 保证唯一）
     * 3. sidecar 文件用 `c+` + LOCK_EX 保证原子性
     *
     * @param string $taskId 任务 ID
     * @param string $type   事件类型（token / tool_start / done 等）
     * @param array  $data   事件负载数据
     * @return int 事件序列号（从 1 开始）；文件操作失败返回 0
     */
    public function appendEvent(string $taskId, string $type, array $data): int
    {
        $this->ensureDir();
        $file = $this->filePath($taskId);
        $seqFile = $this->seqFilePath($taskId);

        // 1. 通过 sidecar 文件原子获取下一个 seq
        $seqFp = @fopen($seqFile, 'c+');
        if ($seqFp === false) {
            return 0;
        }

        if (!flock($seqFp, LOCK_EX)) {
            fclose($seqFp);
            return 0;
        }

        // 读取当前 seq
        $content = stream_get_contents($seqFp);
        $currentSeq = (int)trim($content);
        $nextSeq = $currentSeq + 1;

        // 写回新 seq
        ftruncate($seqFp, 0);
        rewind($seqFp);
        fwrite($seqFp, (string)$nextSeq);
        fflush($seqFp);
        flock($seqFp, LOCK_UN);
        fclose($seqFp);

        // 2. 追加事件到 sse 文件
        $line = $nextSeq . "\t" . $type . "\t" . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";

        $fp = @fopen($file, 'a');
        if ($fp === false) {
            return 0;
        }
        fwrite($fp, $line);
        fflush($fp);
        fclose($fp);

        return $nextSeq;
    }

    /**
     * 读取任务中 seq 大于 afterSeq 的所有事件（按 seq 升序）。
     *
     * 实现要点：
     * 1. 文件不存在返回空数组
     * 2. 以 `r` 模式打开，加共享锁 LOCK_SH（与排他锁互斥，避免读到半写状态）
     * 3. 逐行解析 `{seq}\t{type}\t{json}`，过滤 seq > afterSeq 的行
     * 4. 解析失败或格式不符的行静默跳过
     *
     * @param string $taskId   任务 ID
     * @param int    $afterSeq 返回此 seq 之后的事件（不含等于）
     * @return array<int, array{seq: int, type: string, data: mixed|null}>
     */
    public function readEvents(string $taskId, int $afterSeq = 0): array
    {
        $file = $this->filePath($taskId);
        if (!file_exists($file)) {
            return [];
        }

        $fp = @fopen($file, 'r');
        if ($fp === false) {
            return [];
        }

        $events = [];
        if (flock($fp, LOCK_SH)) {
            while (($line = fgets($fp)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }
                $parts = explode("\t", $line, 3);
                if (count($parts) < 3) {
                    continue;
                }
                $seq = (int)$parts[0];
                if ($seq <= $afterSeq) {
                    continue;
                }
                $events[] = [
                    'seq'  => $seq,
                    'type' => $parts[1],
                    'data' => json_decode($parts[2], true),
                ];
            }
            flock($fp, LOCK_UN);
        }
        fclose($fp);

        return $events;
    }

    // ========================================================================
    //  任务状态
    // ========================================================================

    /**
     * 从 `ai_agent_progress` 表读取任务的粗粒度状态。
     *
     * @param string $taskId 任务 ID
     * @return string 状态值（pending / running / completed / failed）；查询失败或无记录返回 unknown
     */
    public function getTaskStatus(string $taskId): string
    {
        $db = Database::getInstance();
        $row = $db->fetch("SELECT status FROM ai_agent_progress WHERE task_id = ?", [$taskId]);
        return is_array($row) ? ($row['status'] ?? 'unknown') : 'unknown';
    }

    // ========================================================================
    //  清理
    // ========================================================================

    /**
     * 删除修改时间超过 maxAge 秒的 stream 文件。
     *
     * @param int $maxAge 最大保留时长（秒），默认 86400（1 天）
     * @return int 删除的文件数量
     */
    public function cleanupOldFiles(int $maxAge = 86400): int
    {
        if (!is_dir($this->streamDir)) {
            return 0;
        }

        $count = 0;
        $now = time();
        // 清理 .sse 文件
        foreach (glob($this->streamDir . '/*.sse') as $file) {
            if (!is_file($file)) {
                continue;
            }
            if ($now - filemtime($file) > $maxAge) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }
        // 清理 .seq sidecar 文件
        foreach (glob($this->streamDir . '/*.seq') as $file) {
            if (!is_file($file)) {
                continue;
            }
            if ($now - filemtime($file) > $maxAge) {
                @unlink($file);
            }
        }

        return $count;
    }
}
