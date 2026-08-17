<?php

namespace App\Core;

use PDO;
use PDOException;

/**
 * SQLite 性能调优器
 *
 * 弥补 SQLite 与 MySQL 在性能配置上的差距：
 *
 * 【MySQL 自动管理的特性，SQLite 需要手动优化】
 * - InnoDB buffer pool ↔ SQLite PRAGMA cache_size
 * - InnoDB flush log at trx commit ↔ SQLite PRAGMA synchronous
 * - InnoDB checkpoint ↔ SQLite PRAGMA wal_autocheckpoint
 * - MySQL query cache / plan cache ↔ SQLite PRAGMA cache_spill + prepare cache
 *
 * 【优化项】
 * 1. PRAGMA cache_size：默认 -16000(16MB)，根据系统内存动态调整
 * 2. PRAGMA wal_autocheckpoint：默认 1000 页，根据写入负载调整
 * 3. PRAGMA mmap_size：内存映射 IO，大库提升明显
 * 4. PRAGMA temp_store：临时表存内存
 * 5. PRAGMA page_size：首次建库时设为 8192（默认 4096）
 * 6. WAL 检查点策略：低峰期主动 TRUNCATE
 * 7. ANALYZE 频率：写入量大时定期更新统计
 *
 * 设计原则：
 * - 只在 SQLite 模式生效，MySQL/PostgreSQL 自动跳过
 * - 所有 PRAGMA 失败都静默处理（部分环境不支持）
 * - 不修改 Database 类，通过 Database::getPdo() 应用优化
 */
class SqlitePerformanceTuner
{
    private static $instance = null;
    private $db;
    private $dbType;
    private $pdo;

    /** 优化是否已应用（每进程一次） */
    private bool $optimized = false;

    /** 上次 WAL 检查点时间 */
    private int $lastCheckpoint = 0;

    /** 上次 ANALYZE 时间 */
    private int $lastAnalyze = 0;

    /** WAL 检查点间隔（秒）：低峰期每 30 分钟一次 */
    public const CHECKPOINT_INTERVAL = 1800;

    /** ANALYZE 间隔（秒）：每 6 小时一次 */
    public const ANALYZE_INTERVAL = 21600;

    /** WAL 文件大小阈值（字节）：超过 50MB 触发检查点 */
    public const WAL_SIZE_THRESHOLD = 52428800;

    private function __construct()
    {
        $this->db = Database::getInstance();
        $this->dbType = $this->db->getDbType();
        $this->pdo = $this->db->getPdo();
    }

    public static function getInstance(): static
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    /**
     * 应用所有性能优化（每进程只执行一次）。
     *
     * 应在 Database 初始化后、SchemaManager 执行前调用。
     * 实际由 ConcurrencyGuard::isSchemaInitialized() 间接控制执行时机。
     *
     * @return array 应用的优化项列表
     */
    public function optimize(): array
    {
        if ($this->dbType !== 'sqlite') {
            return ['skipped' => true, 'reason' => 'not sqlite'];
        }

        if ($this->optimized) {
            return ['skipped' => true, 'reason' => 'already optimized'];
        }

        $applied = [];

        // 1. 缓存大小：根据系统内存动态调整
        $cacheSize = $this->calculateOptimalCacheSize();
        if ($this->applyPragma('cache_size', $cacheSize)) {
            $applied['cache_size'] = $cacheSize;
        }

        // 2. WAL 自动检查点：设为 2000 页（约 8MB-16MB）
        if ($this->applyPragma('wal_autocheckpoint', 2000)) {
            $applied['wal_autocheckpoint'] = 2000;
        }

        // 3. 内存映射 IO：256MB（大库提升顺序扫描性能）
        if ($this->applyPragma('mmap_size', 268435456)) {
            $applied['mmap_size'] = 268435456;
        }

        // 4. 临时表存内存（避免临时文件 IO）
        if ($this->applyPragma('temp_store', 2)) {
            $applied['temp_store'] = 'MEMORY';
        }

        // 5. 页大小：只能在空库设置，已有数据时跳过
        //    由 SchemaManager 在首次建表前处理

        // 6. 启用 WAL 递归（WAL2 模式实验性，暂不启用）

        // 7. 存储 IO 延迟：synchronous=NORMAL 已在 Database 设置

        $this->optimized = true;
        return $applied;
    }

    /**
     * 根据系统内存计算最优缓存大小。
     *
     * SQLite 的 cache_size 单位是页（负数表示 KB）。
     * - cache_size=-64000 表示 64MB
     *
     * 策略：
     * - 系统内存 < 1GB：32MB（-32000）
     * - 系统内存 < 4GB：64MB（-64000）
     * - 系统内存 < 16GB：128MB（-128000）
     * - 系统内存 >= 16GB：256MB（-256000）
     */
    private function calculateOptimalCacheSize(): int
    {
        $memLimit = ini_get('memory_limit');
        $memBytes = $this->parseMemoryLimit($memLimit);

        // 如果 PHP 内存限制 < 128MB，用较小的缓存
        if ($memBytes > 0 && $memBytes < 128 * 1024 * 1024) {
            return -32000; // 32MB
        }

        // 尝试获取系统内存
        $systemMem = $this->getSystemMemory();

        if ($systemMem <= 0) {
            return -64000; // 默认 64MB
        }
        if ($systemMem < 1024 * 1024 * 1024) {
            return -32000; // 32MB
        }
        if ($systemMem < 4 * 1024 * 1024 * 1024) {
            return -64000; // 64MB
        }
        if ($systemMem < 16 * 1024 * 1024 * 1024) {
            return -128000; // 128MB
        }
        return -256000; // 256MB
    }

    /**
     * 解析 PHP memory_limit 配置值为字节。
     */
    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1' || $limit === '') {
            return -1;
        }
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int)$limit;

        switch ($last) {
            case 'g': $value *= 1024;
            case 'm': $value *= 1024;
            case 'k': $value *= 1024;
        }
        return $value;
    }

    /**
     * 获取系统物理内存（字节），失败返回 0。
     */
    private function getSystemMemory(): int
    {
        // Windows
        if (PHP_OS_FAMILY === 'Windows') {
            $output = @shell_exec('wmic ComputerSystem get TotalPhysicalMemory /value 2>nul');
            if ($output && preg_match('/=(\d+)/', $output, $m)) {
                return (int)$m[1];
            }
        }

        // Linux: /proc/meminfo
        // 先检查 open_basedir，避免 is_readable()/file_get_contents() 触发 Warning。
        // 部分宿主环境自定义 error_handler 会绕过 @ 抑制符，导致日志污染。
        if (PHP_OS_FAMILY === 'Linux' && $this->isPathAllowed('/proc/meminfo')) {
            $content = @file_get_contents('/proc/meminfo');
            if ($content && preg_match('/MemTotal:\s+(\d+)\s+kB/i', $content, $m)) {
                return (int)$m[1] * 1024;
            }
        }

        // macOS
        if (PHP_OS_FAMILY === 'Darwin') {
            $output = @shell_exec('sysctl -n hw.memsize 2>/dev/null');
            if ($output) {
                return (int)trim($output);
            }
        }

        return 0;
    }

    /**
     * 检查路径是否在 open_basedir 允许范围内。
     * open_basedir 未设置时返回 true（无限制）。
     */
    private function isPathAllowed(string $path): bool
    {
        $openBasedir = ini_get('open_basedir');
        if (!$openBasedir) {
            return true; // 无限制
        }
        $allowed = explode(PATH_SEPARATOR, $openBasedir);
        foreach ($allowed as $dir) {
            $dir = rtrim($dir, '/\\');
            if ($dir !== '' && (stripos($path, $dir) === 0 || stripos($dir, dirname($path)) === 0)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 安全应用 PRAGMA，失败静默。
     */
    private function applyPragma(string $name, $value): bool
    {
        try {
            $this->pdo->exec("PRAGMA {$name}={$value}");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * 周期性维护：WAL 检查点 + ANALYZE。
     *
     * 应在请求结束时（如 register_shutdown_function）调用，
     * 利用低峰期执行昂贵的维护操作，避免影响用户体验。
     *
     * @param bool $force 是否强制执行（忽略时间间隔）
     * @return array 执行的操作
     */
    public function periodicMaintenance(bool $force = false): array
    {
        if ($this->dbType !== 'sqlite') {
            return ['skipped' => true];
        }

        $now = time();
        $actions = [];

        // WAL 检查点
        $walStatus = ConcurrencyGuard::getInstance()->getWalStatus();
        $walSize = $walStatus['wal_file_size'] ?? 0;

        $shouldCheckpoint = $force
            || ($now - $this->lastCheckpoint) > self::CHECKPOINT_INTERVAL
            || $walSize > self::WAL_SIZE_THRESHOLD;

        if ($shouldCheckpoint) {
            $mode = $walSize > self::WAL_SIZE_THRESHOLD ? 'TRUNCATE' : 'PASSIVE';
            if (ConcurrencyGuard::getInstance()->walCheckpoint($mode)) {
                $this->lastCheckpoint = $now;
                $actions['wal_checkpoint'] = $mode;
            }
        }

        // ANALYZE（更新查询计划统计）
        if ($force || ($now - $this->lastAnalyze) > self::ANALYZE_INTERVAL) {
            if (ConcurrencyGuard::getInstance()->analyze()) {
                $this->lastAnalyze = $now;
                $actions['analyze'] = true;
            }
        }

        return $actions;
    }

    /**
     * 注册 shutdown 函数，在请求结束时执行周期性维护。
     *
     * 利用 fastcgi_finish_request 后的空闲时间执行维护，
     * 不影响用户响应时间。
     */
    public static function registerShutdownMaintenance(): void
    {
        if (PHP_SAPI !== 'cli') {
            register_shutdown_function(function () {
                // 如果支持 fastcgi_finish_request，先完成响应
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }

                try {
                    // 随机采样，避免每个请求都执行
                    if (random_int(1, 20) === 1) {
                        self::getInstance()->periodicMaintenance();
                    }
                } catch (\Throwable $e) {
                    // 维护失败不影响正常流程
                }
            });
        }
    }
}
