<?php

namespace App\Core;

class AdaptiveRateLimiter
{
    private static $instance = null;
    private $db;
    private $tableCreated = false;
    private $bucketCache = [];
    private $lastCleanup = 0;
    /**
     * shutdown flush 是否已注册。getInstance 多次调用只注册一次。
     */
    private static $shutdownRegistered = false;

    /**
     * write-behind 写盘阈值：
     * - SAVE_MIN_INTERVAL：同一 bucket 两次 DB 写盘最小间隔（秒），避免高频 UPDATE
     * - SAVE_TOKEN_DELTA：tokens 相对 max_tokens 变化超过此比例时强制写盘
     *
     * 拒绝（allowed=false）场景无视上述阈值立即写盘，防止并发请求突破限流。
     * 允许场景的脏桶在请求结束时由 register_shutdown_function 触发 flushDirty 写盘。
     */
    const SAVE_MIN_INTERVAL = 5;
    const SAVE_TOKEN_DELTA  = 0.1;

    const COST_TINY   = 0.1;
    const COST_SMALL  = 0.2;
    const COST_MEDIUM = 0.5;
    const COST_LARGE  = 2;
    const COST_XLARGE = 5;

    const TIER_TINY   = 256 * 1024;
    const TIER_SMALL  = 1 * 1024 * 1024;
    const TIER_MEDIUM = 10 * 1024 * 1024;
    const TIER_LARGE  = 100 * 1024 * 1024;

    const HISTORY_WINDOW = 180;
    const SAMPLE_MAX = 80;

    private function __construct()
    {
        $this->db = Database::getInstance();
        $this->ensureTable();
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([$this, 'flushDirty']);
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    private function ensureTable()
    {
        if ($this->tableCreated) return;
        try {
            $dbType = $this->db->getDbType();
            if ($dbType === 'mysql') {
                // MySQL 不允许 TEXT 做 PRIMARY KEY，需用 VARCHAR + 前缀长度
                $this->db->getPdo()->exec("CREATE TABLE IF NOT EXISTS rate_limit_buckets (
                    bucket_key VARCHAR(255) PRIMARY KEY,
                    tokens DOUBLE NOT NULL DEFAULT 300,
                    max_tokens DOUBLE NOT NULL DEFAULT 300,
                    refill_rate DOUBLE NOT NULL DEFAULT 5.0,
                    last_refill INTEGER NOT NULL DEFAULT 0,
                    recent_sizes TEXT DEFAULT NULL,
                    pattern VARCHAR(50) DEFAULT 'unknown',
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } else {
                // SQLite / PostgreSQL: TEXT PRIMARY KEY 合法
                $this->db->getPdo()->exec("CREATE TABLE IF NOT EXISTS rate_limit_buckets (
                    bucket_key TEXT PRIMARY KEY,
                    tokens REAL NOT NULL DEFAULT 300,
                    max_tokens REAL NOT NULL DEFAULT 300,
                    refill_rate REAL NOT NULL DEFAULT 5.0,
                    last_refill INTEGER NOT NULL DEFAULT 0,
                    recent_sizes TEXT DEFAULT '[]',
                    pattern TEXT DEFAULT 'unknown',
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL
                )");
            }
            $this->db->getPdo()->exec("CREATE INDEX IF NOT EXISTS idx_rlb_updated ON rate_limit_buckets (updated_at)");
            $this->tableCreated = true;
        } catch (\PDOException $e) {}
    }

    public static function costForSize($fileSize)
    {
        if ($fileSize <= self::TIER_TINY)  return self::COST_TINY;
        if ($fileSize <= self::TIER_SMALL) return self::COST_SMALL;
        if ($fileSize <= self::TIER_MEDIUM) return self::COST_MEDIUM;
        if ($fileSize <= self::TIER_LARGE) return self::COST_LARGE;
        return self::COST_XLARGE;
    }

    public static function sizeTier($fileSize)
    {
        if ($fileSize <= self::TIER_TINY)  return 'tiny';
        if ($fileSize <= self::TIER_SMALL) return 'small';
        if ($fileSize <= self::TIER_MEDIUM) return 'medium';
        if ($fileSize <= self::TIER_LARGE) return 'large';
        return 'xlarge';
    }

    public function checkUpload($userId, $fileSize = 0)
    {
        $key = "upload_user_{$userId}";
        $cost = self::costForSize($fileSize);
        return $this->adaptiveCheck($key, $cost, $fileSize, 'upload');
    }

    public function checkUploadChunk($userId)
    {
        $key = "upload_chunk_user_{$userId}";
        // 分片上传令牌成本极低（0.02），大批量上传可能有数百个分片，
        // 确保初始令牌和补充速率足够容纳整批分片不间断
        return $this->adaptiveCheck($key, 0.02, 0, 'chunk');
    }

    public function adaptiveCheck($bucketKey, $cost, $fileSize = 0, $actionType = 'generic')
    {
        $now = time();
        $bucket = $this->loadBucket($bucketKey, $actionType);

        $elapsed = max(0, $now - $bucket['last_refill']);
        $newTokens = $elapsed * $bucket['refill_rate'];
        $bucket['tokens'] = min($bucket['max_tokens'], $bucket['tokens'] + $newTokens);
        $bucket['last_refill'] = $now;

        if ($fileSize > 0 && $actionType === 'upload') {
            $bucket['recent_sizes'][] = ['size' => $fileSize, 'time' => $now];
            while (count($bucket['recent_sizes']) > self::SAMPLE_MAX) {
                array_shift($bucket['recent_sizes']);
            }
            $bucket['recent_sizes'] = array_values(array_filter(
                $bucket['recent_sizes'],
                function ($s) use ($now) { return ($now - $s['time']) <= self::HISTORY_WINDOW; }
            ));

            $this->adaptRate($bucket, $actionType, $fileSize);
            $bucket['tokens'] = min($bucket['max_tokens'], $bucket['tokens']);
        }

        if ($bucket['tokens'] < $cost) {
            $waitTime = ceil(($cost - $bucket['tokens']) / max($bucket['refill_rate'], 0.01));
            $bucket['tokens'] = max(0, $bucket['tokens']);
            // 拒绝时立即写盘：防止并发请求读到旧 tokens 突破限流
            $this->saveBucket($bucketKey, $bucket, true);
            return [
                'allowed' => false,
                'retry_after' => min($waitTime, 60),
                'tokens_left' => round($bucket['tokens'], 2),
                'max_tokens' => round($bucket['max_tokens'], 2),
                'refill_rate' => round($bucket['refill_rate'], 4),
                'pattern' => $bucket['pattern'],
            ];
        }

        $bucket['tokens'] -= $cost;

        $usageRatio = $bucket['max_tokens'] > 0
            ? 1.0 - ($bucket['tokens'] / $bucket['max_tokens'])
            : 0;

        $this->saveBucket($bucketKey, $bucket);

        $result = [
            'allowed' => true,
            'tokens_left' => round($bucket['tokens'], 2),
            'max_tokens' => round($bucket['max_tokens'], 2),
            'refill_rate' => round($bucket['refill_rate'], 4),
            'pattern' => $bucket['pattern'],
            'usage_ratio' => round($usageRatio, 3),
        ];

        if ($usageRatio > 0.85) {
            $result['warning'] = true;
        }
        if ($usageRatio > 0.9) {
            $slowdown = 2 + (($usageRatio - 0.9) * 20);
            $result['slowdown_ms'] = (int)($slowdown * 100);
        }

        return $result;
    }

    private function adaptRate(&$bucket, $actionType, $currentFileSize)
    {
        if ($actionType !== 'upload') return;

        $tier = self::sizeTier($currentFileSize);
        $sizes = array_column($bucket['recent_sizes'], 'size');
        $totalCount = count($sizes);

        if ($totalCount < 3) {
            $this->quickAdapt($bucket, $tier);
            return;
        }

        $avgSize = array_sum($sizes) / $totalCount;
        sort($sizes);
        $medianSize = $sizes[(int)($totalCount / 2)];

        $tinyCount = 0;
        $smallCount = 0;
        foreach ($sizes as $s) {
            if ($s <= self::TIER_TINY) $tinyCount++;
            if ($s <= self::TIER_SMALL) $smallCount++;
        }
        $tinyRatio = $tinyCount / $totalCount;
        $smallRatio = $smallCount / $totalCount;

        $uploadRate = $totalCount / max(1, (time() - ($bucket['recent_sizes'][0]['time'] ?? time())));
        $uploadRate = min($uploadRate, 30);

        if ($tinyRatio > 0.2) {
            $bucket['pattern'] = 'tiny_burst';
            $bucket['max_tokens'] = 300 + ($tinyRatio * 700);
            $bucket['refill_rate'] = 5.0 + ($uploadRate * 0.8);
        } elseif ($smallRatio > 0.3) {
            $bucket['pattern'] = 'small_burst';
            $bucket['max_tokens'] = 200 + ($smallRatio * 400);
            $bucket['refill_rate'] = 3.0 + ($uploadRate * 0.5);
        } elseif ($avgSize < self::TIER_MEDIUM) {
            $bucket['pattern'] = 'medium_mix';
            $bucket['max_tokens'] = 100;
            $bucket['refill_rate'] = 2.0;
        } else {
            $bucket['pattern'] = 'large_files';
            $bucket['max_tokens'] = 30;
            $bucket['refill_rate'] = 0.5;
        }

        $bucket['max_tokens'] = round(max(30, min(1000, $bucket['max_tokens'])), 2);
        $bucket['refill_rate'] = round(max(0.5, min(15.0, $bucket['refill_rate'])), 4);
    }

    private function quickAdapt(&$bucket, $tier)
    {
        if ($tier === 'tiny' || $tier === 'small') {
            $bucket['pattern'] = 'tiny_burst';
            $bucket['max_tokens'] = 400;
            $bucket['refill_rate'] = 6.0;
        } elseif ($tier === 'medium') {
            $bucket['pattern'] = 'medium_mix';
            $bucket['max_tokens'] = 150;
            $bucket['refill_rate'] = 2.5;
        } else {
            $bucket['pattern'] = 'large_files';
            $bucket['max_tokens'] = 50;
            $bucket['refill_rate'] = 0.8;
        }
    }

    private function loadBucket($key, $actionType)
    {
        if ((time() - $this->lastCleanup) > 1800) {
            $this->lastCleanup = time();
            $this->cleanupExpired(3600);
        }

        if (isset($this->bucketCache[$key])) {
            $cached = $this->bucketCache[$key];
            if ((time() - $cached['_cache_time']) < 30) {
                return $cached;
            }
        }

        $defaults = $this->defaultsFor($actionType);

        try {
            $row = $this->db->fetch(
                "SELECT * FROM rate_limit_buckets WHERE bucket_key = ?",
                [$key]
            );
            if ($row) {
                $bucket = [
                    'tokens' => (float)$row['tokens'],
                    'max_tokens' => (float)$row['max_tokens'],
                    'refill_rate' => (float)$row['refill_rate'],
                    'last_refill' => (int)$row['last_refill'],
                    'recent_sizes' => json_decode($row['recent_sizes'], true) ?: [],
                    'pattern' => $row['pattern'],
                ];
                $bucket['_cache_time'] = time();
                $this->bucketCache[$key] = $bucket;
                return $bucket;
            }
        } catch (\PDOException $e) {}

        $defaults['_cache_time'] = time();
        $this->bucketCache[$key] = $defaults;
        return $defaults;
    }

    private function defaultsFor($actionType)
    {
        $calibrated = ServerBenchmark::getCalibratedLimits();

        if ($actionType === 'upload' || $actionType === 'chunk') {
            $this->maybeTriggerRecalibration($calibrated);
        }

        switch ($actionType) {
            case 'upload':
                $maxTokens = 300;
                $refillRate = 5.0;
                if ($calibrated && isset($calibrated['upload'])) {
                    $maxTokens = $calibrated['upload']['max_tokens'];
                    $refillRate = $calibrated['upload']['refill_rate'];
                }
                return [
                    'tokens' => $maxTokens,
                    'max_tokens' => $maxTokens,
                    'refill_rate' => $refillRate,
                    'last_refill' => time(),
                    'recent_sizes' => [],
                    'pattern' => $calibrated ? ('calibrated_' . ($calibrated['tier'] ?? 'unknown')) : 'warmup',
                ];
            case 'chunk':
                $chunkTokens = 500;
                $chunkRefill = 10.0;
                if ($calibrated && isset($calibrated['chunk'])) {
                    $chunkTokens = $calibrated['chunk']['max_tokens'];
                    $chunkRefill = $calibrated['chunk']['refill_rate'];
                }
                return [
                    'tokens' => $chunkTokens,
                    'max_tokens' => $chunkTokens,
                    'refill_rate' => $chunkRefill,
                    'last_refill' => time(),
                    'recent_sizes' => [],
                    'pattern' => 'chunk_default',
                ];
            default:
                return [
                    'tokens' => 60,
                    'max_tokens' => 60,
                    'refill_rate' => 1.0,
                    'last_refill' => time(),
                    'recent_sizes' => [],
                    'pattern' => 'unknown',
                ];
        }
    }

    /**
     * 写 bucket。write-behind 策略：
     * - $immediate=true：立即写 DB（拒绝场景，防并发突破）
     * - $immediate=false：仅当距上次写盘超过 SAVE_MIN_INTERVAL 秒
     *   或 tokens 变化超过 SAVE_TOKEN_DELTA 比例时才写盘；
     *   否则仅更新内存并标记 _dirty，由 shutdown flush 兜底落盘。
     *
     * 这样高频允许场景（正常上传分片）每请求 DB 写减少到约 1/SAVE_MIN_INTERVAL。
     */
    private function saveBucket($key, $bucket, $immediate = false)
    {
        $now = time();
        $bucket['_cache_time'] = $now;

        $prev = $this->bucketCache[$key] ?? null;
        $prevWriteTime = $prev['_last_db_write'] ?? 0;
        $prevTokens = $prev['tokens'] ?? $bucket['tokens'];
        $tokenDelta = abs($bucket['tokens'] - $prevTokens);
        $significantChange = $bucket['max_tokens'] > 0
            ? ($tokenDelta / $bucket['max_tokens']) >= self::SAVE_TOKEN_DELTA
            : $tokenDelta >= 5.0;
        $staleWrite = ($now - $prevWriteTime) >= self::SAVE_MIN_INTERVAL;

        // 先更新内存（无论是否写盘，内存都要最新）
        $bucket['_dirty'] = !$immediate && !$significantChange && !$staleWrite;
        if (!$bucket['_dirty']) {
            $bucket['_last_db_write'] = $now;
        } else {
            // 保留上次写盘时间，等待下次触发
            $bucket['_last_db_write'] = $prevWriteTime;
        }
        $this->bucketCache[$key] = $bucket;

        if ($bucket['_dirty']) {
            return; // 延迟写盘
        }

        $this->writeBucketToDb($key, $bucket, $now);
    }

    /**
     * 实际执行 DB 写盘（INSERT OR UPDATE）。
     */
    private function writeBucketToDb($key, $bucket, $now)
    {
        $recentSizesJson = json_encode($bucket['recent_sizes'] ?: []);

        try {
            $existing = $this->db->fetch(
                "SELECT bucket_key FROM rate_limit_buckets WHERE bucket_key = ?",
                [$key]
            );

            if ($existing) {
                $this->db->update('rate_limit_buckets', [
                    'tokens' => $bucket['tokens'],
                    'max_tokens' => $bucket['max_tokens'],
                    'refill_rate' => $bucket['refill_rate'],
                    'last_refill' => $bucket['last_refill'],
                    'recent_sizes' => $recentSizesJson,
                    'pattern' => $bucket['pattern'],
                    'updated_at' => $now,
                ], 'bucket_key = ?', [$key]);
            } else {
                $this->db->insert('rate_limit_buckets', [
                    'bucket_key' => $key,
                    'tokens' => $bucket['tokens'],
                    'max_tokens' => $bucket['max_tokens'],
                    'refill_rate' => $bucket['refill_rate'],
                    'last_refill' => $bucket['last_refill'],
                    'recent_sizes' => $recentSizesJson,
                    'pattern' => $bucket['pattern'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        } catch (\PDOException $e) {}
    }

    /**
     * 请求结束时 flush 所有脏桶。由 register_shutdown_function 触发。
     * 确保允许场景下未立即落盘的 token 扣减不丢失（防止进程退出后状态回退）。
     */
    public function flushDirty()
    {
        foreach ($this->bucketCache as $key => $bucket) {
            if (!empty($bucket['_dirty'])) {
                $now = time();
                $bucket['_dirty'] = false;
                $bucket['_last_db_write'] = $now;
                $this->bucketCache[$key] = $bucket;
                $this->writeBucketToDb($key, $bucket, $now);
            }
        }
    }

    public function clearBucket($key)
    {
        unset($this->bucketCache[$key]);
        try {
            $this->db->delete('rate_limit_buckets', 'bucket_key = ?', [$key]);
        } catch (\PDOException $e) {}
    }

    public function getBucketStats($key)
    {
        try {
            return $this->db->fetch(
                "SELECT * FROM rate_limit_buckets WHERE bucket_key = ?",
                [$key]
            );
        } catch (\PDOException $e) {
            return null;
        }
    }

    public function cleanupExpired($olderThanSeconds = 7200)
    {
        $cutoff = time() - $olderThanSeconds;
        try {
            $this->db->delete('rate_limit_buckets', 'updated_at < ?', [$cutoff]);
        } catch (\PDOException $e) {}
    }

    private function maybeTriggerRecalibration($calibrated)
    {
        if (!$calibrated || empty($calibrated['benchmarked_at'])) return;

        $age = time() - $calibrated['benchmarked_at'];
        if ($age < 86400) return;

        $lockFile = DATA_PATH . DIRECTORY_SEPARATOR . '.recal_lock';
        if (file_exists($lockFile) && (time() - @filemtime($lockFile)) < 7200) return;

        @touch($lockFile);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        try {
            ServerBenchmark::maybeRecalibrate();
        } catch (\Throwable $e) {}
    }
}
