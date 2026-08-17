<?php

namespace App\Core;

class ServerBenchmark
{
    private $config;
    private $results = [];
    private $testDir;

    const TEST_FILE_SIZE = 3 * 1024 * 1024;
    const DB_ROW_COUNT = 100;
    const HASH_TEST_SIZE = 3 * 1024 * 1024;
    const RUNS_PER_BENCH = 3;
    const WARMUP_RUNS = 1;
    const MAX_DEVIATION_RATIO = 2.5;

    public function __construct()
    {
        $this->config = Config::getInstance();
        $this->testDir = DATA_PATH . DIRECTORY_SEPARATOR . '.bench';
    }

    public function run($lightweight = false)
    {
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0755, true);
        }

        $runs = $lightweight ? max(2, self::RUNS_PER_BENCH - 1) : self::RUNS_PER_BENCH;

        $this->benchDiskWrite($runs);
        $this->benchDiskRead($runs);
        $this->benchDbWrite($runs);
        $this->benchDbRead($runs);
        $this->benchHashing($runs);

        $this->cleanup();
        $this->calculateLimits();
        $this->saveResults();

        return $this->results;
    }

    private function benchDiskWrite($runs)
    {
        $samples = [];
        $testFile = $this->testDir . DIRECTORY_SEPARATOR . 'write_test.bin';

        $totalRuns = self::WARMUP_RUNS + $runs;
        for ($run = 0; $run < $totalRuns; $run++) {
            $data = random_bytes(512 * 1024);
            $iterations = max(1, (int)(self::TEST_FILE_SIZE / strlen($data)));
            $totalWritten = 0;

            $start = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                file_put_contents($testFile, $data, $i === 0 ? 0 : FILE_APPEND);
                $totalWritten += strlen($data);
            }
            $elapsed = max(microtime(true) - $start, 0.001);
            $speedMB = ($totalWritten / 1048576) / $elapsed;

            if ($run >= self::WARMUP_RUNS) {
                $samples[] = $speedMB;
            }
            @unlink($testFile);
            clearstatcache(true, $testFile);
        }

        $this->results['disk_write_mbps'] = $this->stableValue($samples, 'disk_write');
    }

    private function benchDiskRead($runs)
    {
        $samples = [];
        $testFile = $this->testDir . DIRECTORY_SEPARATOR . 'read_test.bin';

        $totalRuns = self::WARMUP_RUNS + $runs;
        for ($run = 0; $run < $totalRuns; $run++) {
            $data = random_bytes(self::TEST_FILE_SIZE);
            file_put_contents($testFile, $data);
            clearstatcache(true, $testFile);
            $filesize = filesize($testFile);

            $start = microtime(true);
            $content = file_get_contents($testFile);
            $elapsed = max(microtime(true) - $start, 0.001);
            $speedMB = ($filesize / 1048576) / $elapsed;

            if ($run >= self::WARMUP_RUNS) {
                $samples[] = $speedMB;
            }
            @unlink($testFile);
            clearstatcache(true, $testFile);
        }

        $this->results['disk_read_mbps'] = $this->stableValue($samples, 'disk_read');
    }

    private function benchDbWrite($runs)
    {
        $db = Database::getInstance();
        $dbType = $db->getDbType();

        $db->getPdo()->exec("DROP TABLE IF EXISTS _bench_test");
        if ($dbType === 'mysql') {
            $db->getPdo()->exec("CREATE TABLE _bench_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                payload TEXT NOT NULL,
                idx INT NOT NULL,
                created_at INT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } elseif ($dbType === 'pgsql') {
            $db->getPdo()->exec("CREATE TABLE _bench_test (
                id SERIAL PRIMARY KEY,
                payload TEXT NOT NULL,
                idx INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            )");
        } else {
            $db->getPdo()->exec("CREATE TABLE _bench_test (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                payload TEXT NOT NULL,
                idx INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            )");
        }

        $samples = [];
        $rows = min(self::DB_ROW_COUNT, 200);

        $totalRuns = self::WARMUP_RUNS + $runs;
        for ($run = 0; $run < $totalRuns; $run++) {
            $db->getPdo()->exec("DELETE FROM _bench_test");
            $payload = bin2hex(random_bytes(256));
            $totalTime = 0;

            $insertStmt = $db->getPdo()->prepare("INSERT INTO _bench_test (payload, idx, created_at) VALUES (?, ?, ?)");
            for ($i = 0; $i < $rows; $i++) {
                $t0 = microtime(true);
                $insertStmt->execute([$payload . $i, $i, time()]);
                $totalTime += microtime(true) - $t0;
            }

            $elapsed = max($totalTime, 0.001);
            if ($run >= self::WARMUP_RUNS) {
                $samples[] = $rows / $elapsed;
            }
        }

        $db->getPdo()->exec("DROP TABLE IF EXISTS _bench_test");
        $this->results['db_writes_per_sec'] = $this->stableValue($samples, 'db_write');
    }

    private function benchDbRead($runs)
    {
        $db = Database::getInstance();
        $dbType = $db->getDbType();

        if ($dbType === 'mysql') {
            $db->getPdo()->exec("CREATE TABLE IF NOT EXISTS _bench_read (
                id INT PRIMARY KEY AUTO_INCREMENT,
                payload TEXT NOT NULL,
                idx INT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } elseif ($dbType === 'pgsql') {
            $db->getPdo()->exec("CREATE TABLE IF NOT EXISTS _bench_read (
                id SERIAL PRIMARY KEY,
                payload TEXT NOT NULL,
                idx INTEGER NOT NULL
            )");
        } else {
            $db->getPdo()->exec("CREATE TABLE IF NOT EXISTS _bench_read (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                payload TEXT NOT NULL,
                idx INTEGER NOT NULL
            )");
        }
        $db->getPdo()->exec("DELETE FROM _bench_read");

        $rows = min(self::DB_ROW_COUNT, 200);
        $payload = bin2hex(random_bytes(256));
        $insertStmt = $db->getPdo()->prepare("INSERT INTO _bench_read (payload, idx) VALUES (?, ?)");
        for ($i = 0; $i < $rows; $i++) {
            $insertStmt->execute([$payload . $i, $i]);
        }

        $samples = [];
        $totalRuns = self::WARMUP_RUNS + $runs;
        for ($run = 0; $run < $totalRuns; $run++) {
            $start = microtime(true);
            for ($i = 0; $i < $rows; $i++) {
                $db->fetch("SELECT * FROM _bench_read WHERE idx = ?", [$i]);
            }
            $elapsed = max(microtime(true) - $start, 0.001);
            if ($run >= self::WARMUP_RUNS) {
                $samples[] = $rows / $elapsed;
            }
        }

        $db->getPdo()->exec("DROP TABLE IF EXISTS _bench_read");
        $this->results['db_reads_per_sec'] = $this->stableValue($samples, 'db_read');
    }

    private function benchHashing($runs)
    {
        $md5Samples = [];
        $shaSamples = [];
        $data = random_bytes(self::HASH_TEST_SIZE);

        $totalRuns = self::WARMUP_RUNS + $runs;
        for ($run = 0; $run < $totalRuns; $run++) {
            $start = microtime(true);
            md5($data);
            $md5Elapsed = max(microtime(true) - $start, 0.001);

            $start = microtime(true);
            hash('sha256', $data);
            $shaElapsed = max(microtime(true) - $start, 0.001);

            if ($run >= self::WARMUP_RUNS) {
                $md5Samples[] = (self::HASH_TEST_SIZE / 1048576) / $md5Elapsed;
                $shaSamples[] = (self::HASH_TEST_SIZE / 1048576) / $shaElapsed;
            }
        }

        $this->results['hash_md5_mbps'] = $this->stableValue($md5Samples, 'hash_md5');
        $this->results['hash_sha256_mbps'] = $this->stableValue($shaSamples, 'hash_sha256');
    }

    private function stableValue($samples, $label)
    {
        $count = count($samples);
        if ($count === 0) return 0;
        if ($count === 1) return round($samples[0], 2);

        $sorted = $samples;
        sort($sorted);
        $median = $sorted[(int)($count / 2)];

        $filtered = [];
        foreach ($samples as $v) {
            if ($median > 0 && ($v / $median) < self::MAX_DEVIATION_RATIO
                && ($median / max($v, 0.001)) < self::MAX_DEVIATION_RATIO) {
                $filtered[] = $v;
            }
        }

        if (count($filtered) > 0) {
            $avg = array_sum($filtered) / count($filtered);
        } else {
            $avg = $median;
        }

        $this->results['_debug_' . $label . '_samples'] = array_map(function ($v) { return round($v, 2); }, $samples);
        $this->results['_debug_' . $label . '_used'] = round($avg, 2);

        return round($avg, 2);
    }

    private function calculateLimits()
    {
        $diskWrite = $this->results['disk_write_mbps'] ?? 30;
        $dbWrite = $this->results['db_writes_per_sec'] ?? 100;
        $hashSpeed = $this->results['hash_md5_mbps'] ?? 100;

        $performanceScore = ($diskWrite * 0.5) + ($dbWrite * 0.3) + ($hashSpeed * 0.2);

        if ($performanceScore < 20) {
            $tier = 'low';
        } elseif ($performanceScore < 100) {
            $tier = 'medium';
        } elseif ($performanceScore < 400) {
            $tier = 'high';
        } else {
            $tier = 'extreme';
        }

        $uploadTokens = (int)($diskWrite * 12);
        $uploadTokens = max(80, min(1500, $uploadTokens));
        $uploadRefill = $diskWrite * 0.2;
        $uploadRefill = max(1.5, min(20.0, $uploadRefill));

        $chunkTokens = (int)($diskWrite * 30);
        $chunkTokens = max(150, min(2000, $chunkTokens));
        $chunkRefill = $diskWrite * 0.5;
        $chunkRefill = max(3.0, min(30.0, $chunkRefill));

        $this->results['tier'] = $tier;
        $this->results['performance_score'] = round($performanceScore, 2);
        $this->results['calibrated_upload_max_tokens'] = $uploadTokens;
        $this->results['calibrated_upload_refill_rate'] = round($uploadRefill, 4);
        $this->results['calibrated_chunk_max_tokens'] = $chunkTokens;
        $this->results['calibrated_chunk_refill_rate'] = round($chunkRefill, 4);
        $this->results['benchmarked_at'] = time();
    }

    private function saveResults()
    {
        $this->results['_debug'] = [
            'disk_write' => $this->results['_debug_disk_write_used'] ?? null,
            'disk_read' => $this->results['_debug_disk_read_used'] ?? null,
            'db_write' => $this->results['_debug_db_write_used'] ?? null,
            'db_read' => $this->results['_debug_db_read_used'] ?? null,
            'hash_md5' => $this->results['_debug_hash_md5_used'] ?? null,
            'hash_sha256' => $this->results['_debug_hash_sha256_used'] ?? null,
        ];

        $this->config->set('benchmark', $this->results);
        $this->config->save();
    }

    private function cleanup()
    {
        foreach (['write_test.bin', 'read_test.bin'] as $f) {
            @unlink($this->testDir . DIRECTORY_SEPARATOR . $f);
        }

        if (is_dir($this->testDir)) {
            $entries = scandir($this->testDir);
            $isEmpty = true;
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $isEmpty = false;
                    break;
                }
            }
            if ($isEmpty) {
                @rmdir($this->testDir);
            }
        }
    }

    public static function getCalibratedLimits()
    {
        $config = Config::getInstance();
        $bench = $config->get('benchmark');

        if (!$bench || empty($bench['calibrated_upload_max_tokens'])) {
            return null;
        }

        return [
            'upload' => [
                'max_tokens' => (float)($bench['calibrated_upload_max_tokens'] ?? 300),
                'refill_rate' => (float)($bench['calibrated_upload_refill_rate'] ?? 5.0),
            ],
            'chunk' => [
                'max_tokens' => (float)($bench['calibrated_chunk_max_tokens'] ?? 500),
                'refill_rate' => (float)($bench['calibrated_chunk_refill_rate'] ?? 10.0),
            ],
            'tier' => $bench['tier'] ?? 'unknown',
            'benchmarked_at' => $bench['benchmarked_at'] ?? 0,
        ];
    }

    public static function maybeRecalibrate()
    {
        $calibrated = self::getCalibratedLimits();
        if (!$calibrated) return;

        $age = time() - $calibrated['benchmarked_at'];
        if ($age < 86400) return;

        try {
            $instance = new static();
            $instance->run(true);
        } catch (\Throwable $e) {}
    }
}
