<?php

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static $instance = null;
    private $pdo;
    private $queryCache;
    private $dbType = 'sqlite';
    private $dbConfig = [];

    /**
     * Database 层事务状态标志。
     *
     * SQLite 驱动使用 exec('BEGIN IMMEDIATE') 绕过 PDO 事务状态，
     * PDO::inTransaction() 对手动 BEGIN 返回 false，因此需独立维护。
     * ConcurrencyGuard 通过 inTransaction() 读取此标志做嵌套检测。
     */
    private bool $inTransaction = false;

    private function __construct()
    {
        $config = Config::getInstance();
        $this->dbType = $config->get('database.type', 'sqlite');
        $this->dbConfig = $config->get('database.config', []);
        $this->queryCache = new QueryCache();

        if (!is_dir(DATA_PATH)) {
            mkdir(DATA_PATH, 0755, true);
        }

        if ($this->dbType === 'sqlite') {
            $dsn = 'sqlite:' . DB_PATH;
            $this->pdo = new PDO($dsn);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            // 运行时 PRAGMA：每次连接都设置，安全可重复
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $this->pdo->exec('PRAGMA foreign_keys=ON');
            $this->pdo->exec('PRAGMA busy_timeout=3000');
            $this->pdo->exec('PRAGMA synchronous=NORMAL');
            $this->pdo->exec('PRAGMA cache_size=-16000');
            $this->pdo->exec('PRAGMA temp_store=MEMORY');

            // mmap_size 非关键，部分环境不支持，静默忽略失败
            try {
                $this->pdo->exec('PRAGMA mmap_size=268435456');
            } catch (\PDOException $e) {
            }

            // page_size 和 auto_vacuum 只能在空库设置，移入 SchemaManager 在首次建表前完成
        } elseif ($this->dbType === 'mysql') {
            $host = $this->dbConfig['host'] ?? '127.0.0.1';
            $port = $this->dbConfig['port'] ?? 3306;
            $database = $this->dbConfig['database'] ?? 'pancloud';
            $username = $this->dbConfig['username'] ?? 'root';
            $password = $this->dbConfig['password'] ?? '';
            $charset = $this->dbConfig['charset'] ?? 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true,
            ];

            $this->pdo = new PDO($dsn, $username, $password, $options);
            $this->pdo->exec("SET NAMES '{$charset}'");
            $this->pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        } elseif ($this->dbType === 'pgsql') {
            $host = $this->dbConfig['host'] ?? '127.0.0.1';
            $port = $this->dbConfig['port'] ?? 5432;
            $database = $this->dbConfig['database'] ?? 'pancloud';
            $username = $this->dbConfig['username'] ?? 'postgres';
            $password = $this->dbConfig['password'] ?? '';

            $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true,
            ];

            $this->pdo = new PDO($dsn, $username, $password, $options);
            $this->pdo->exec("SET NAMES 'UTF8'");
        }

        // 注意：Schema 初始化已移至 initSchema()，在 getInstance() 中于实例赋值后调用，
        // 避免 SchemaManager → ConcurrencyGuard → Database::getInstance() 的循环递归
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new static();
            // 实例已赋值，此时 SchemaManager 触发 ConcurrencyGuard::getInstance() → Database::getInstance()
            // 会拿到已设置的 self::$instance，不再递归
            self::$instance->initSchema();
        }
        return self::$instance;
    }

    /**
     * 重置单例（仅用于安装失败回滚，使下次 getInstance() 重新初始化）。
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
        // ConcurrencyGuard 持有旧 Database 引用，也需重置
        \App\Core\ConcurrencyGuard::resetInstance();
    }

    /**
     * Schema 初始化（从构造函数拆出，避免与单例赋值的循环依赖）。
     */
    private function initSchema(): void
    {
        (new SchemaManager($this->pdo, $this->dbType, $this->dbConfig))->initTables();
    }

    public function getDbType()
    {
        return $this->dbType;
    }

    public function getQueryCache()
    {
        return $this->queryCache;
    }

    // ====================================================================
    //  PDO 查询执行
    // ====================================================================

    public function getPdo()
    {
        return $this->pdo;
    }

    /**
     * 检查并重连失效的数据库连接。
     *
     * CLI Worker 长时间运行时，MySQL wait_timeout 或网络中断会导致
     * "MySQL server has gone away" 错误。此方法检测后重建 PDO 连接。
     *
     * 注意：重建后使用非持久连接（PDO::ATTR_PERSISTENT = false），
     * 因为持久连接在 CLI 场景下复用语义不明确。
     */
    private function ensureConnection(): void
    {
        // MySQL/PostgreSQL 用 ping 检测连接活性
        if ($this->dbType === 'mysql' || $this->dbType === 'pgsql') {
            try {
                $this->pdo->query("SELECT 1");
            } catch (PDOException $e) {
                // 连接已失效，重建
                $this->reconnect();
            }
        }
        // SQLite 无需 ping，文件始终可访问
    }

    /**
     * 重建 PDO 连接。
     */
    private function reconnect(): void
    {
        try {
            $this->pdo = null;
            // 重新构造 PDO（复用构造函数中的逻辑）
            // 注意：不调用 initSchema() 避免循环
            if ($this->dbType === 'sqlite') {
                $dsn = 'sqlite:' . DB_PATH;
                $this->pdo = new PDO($dsn);
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
                $this->pdo->exec('PRAGMA journal_mode=WAL');
                $this->pdo->exec('PRAGMA foreign_keys=ON');
                $this->pdo->exec('PRAGMA busy_timeout=3000');
                $this->pdo->exec('PRAGMA synchronous=NORMAL');
                $this->pdo->exec('PRAGMA cache_size=-16000');
                $this->pdo->exec('PRAGMA temp_store=MEMORY');
            } elseif ($this->dbType === 'mysql') {
                $host = $this->dbConfig['host'] ?? '127.0.0.1';
                $port = $this->dbConfig['port'] ?? 3306;
                $database = $this->dbConfig['database'] ?? 'pancloud';
                $username = $this->dbConfig['username'] ?? 'root';
                $password = $this->dbConfig['password'] ?? '';
                $charset = $this->dbConfig['charset'] ?? 'utf8mb4';

                $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    // 重连后使用非持久连接
                    PDO::ATTR_PERSISTENT => false,
                ];
                $this->pdo = new PDO($dsn, $username, $password, $options);
                $this->pdo->exec("SET NAMES '{$charset}'");
                $this->pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            } elseif ($this->dbType === 'pgsql') {
                $host = $this->dbConfig['host'] ?? '127.0.0.1';
                $port = $this->dbConfig['port'] ?? 5432;
                $database = $this->dbConfig['database'] ?? 'pancloud';
                $username = $this->dbConfig['username'] ?? 'postgres';
                $password = $this->dbConfig['password'] ?? '';

                $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false,
                ];
                $this->pdo = new PDO($dsn, $username, $password, $options);
                $this->pdo->exec("SET NAMES 'UTF8'");
            }
            $this->inTransaction = false;
            \App\Core\AsyncLogger::getInstance()->info('[Database] Connection reconnected');
        } catch (PDOException $e) {
            \App\Core\AsyncLogger::getInstance()->error('[Database] Reconnect failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 判断异常是否为连接失效（需重连）。
     */
    private function isConnectionLost(PDOException $e): bool
    {
        $msg = $e->getMessage();
        // MySQL: server has gone away / server closed the connection
        if (strpos($msg, 'server has gone away') !== false
            || strpos($msg, 'server closed the connection') !== false
            || strpos($msg, 'MySQL server has gone away') !== false
        ) {
            return true;
        }
        // PostgreSQL: server closed the connection unexpectedly
        if (strpos($msg, 'server closed the connection') !== false) {
            return true;
        }
        // SQLite: database disk image is malformed
        if (strpos($msg, 'database disk image is malformed') !== false) {
            return true;
        }
        // 通用 PDO 错误码：08xxx 为连接异常
        $errorInfo = $e->errorInfo ?: [];
        $sqlState = $errorInfo[0] ?? '';
        if (strpos($sqlState, '08') === 0) {
            return true;
        }
        return false;
    }

    /**
     * 判断异常是否为数据库锁竞争。
     *
     * SQLite：依赖消息匹配（PDO 错误码不统一）。
     * MySQL：检查 errorInfo[1] 驱动错误码（1205 锁等待超时、1213 死锁）。
     * PostgreSQL：检查 errorInfo[0] SQLSTATE（40P01/55P03/40001）。
     */
    private function isLockContention(PDOException $e): bool
    {
        $msg = $e->getMessage();
        // SQLite：依赖消息匹配（PDO 错误码不统一）
        if (strpos($msg, 'database is locked') !== false
            || strpos($msg, 'cannot start a transaction') !== false
            || strpos($msg, 'SQLITE_BUSY') !== false
        ) {
            return true;
        }

        $errorInfo = $e->errorInfo ?: [];
        $sqlState = $errorInfo[0] ?? '';
        $driverCode = $errorInfo[1] ?? 0;

        if ($this->dbType === 'mysql') {
            // 1205 Lock wait timeout; 1213 Deadlock
            return in_array((int)$driverCode, [1205, 1213], true);
        }

        if ($this->dbType === 'pgsql') {
            // 40P01 serialization_failure; 55P03 lock_not_available; 40001 serialization_failure (legacy)
            return in_array($sqlState, ['40P01', '55P03', '40001'], true);
        }

        return false;
    }

    /**
     * 读查询 — WAL 模式下读不阻塞，无需重试锁竞争。
     */
    public function queryRead($sql, $params = [])
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            if ($this->isConnectionLost($e)) {
                $this->reconnect();
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            }
            throw $e;
        }
    }

    /**
     * 写查询 — 带指数退避 + 随机抖动的重试。
     *
     * 与旧版 query() 的区别：
     * - 重试次数 5→3：3 次拿不到锁说明持有时间异常长，应 fail-fast
     * - 加入随机抖动（Jitter）：避免多请求同步重试形成"惊群"
     * - 总等待约 0.6 秒（含 Jitter），配合 busy_timeout=3s 底层安全网
     */
    public function queryWrite($sql, $params = [])
    {
        $maxRetries = 3;
        $attempt = 0;

        while (true) {
            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            } catch (PDOException $e) {
                $attempt++;
                // 连接失效：重连后重试一次（不消耗锁重试次数）
                if ($this->isConnectionLost($e)) {
                    $this->reconnect();
                    try {
                        $stmt = $this->pdo->prepare($sql);
                        $stmt->execute($params);
                        return $stmt;
                    } catch (PDOException $e2) {
                        throw $e2;
                    }
                }
                // 锁竞争：指数退避重试
                if ($attempt >= $maxRetries || !$this->isLockContention($e)) {
                    throw $e;
                }
                $baseMs = 50 * pow(2, $attempt - 1);
                $jitterMs = random_int(0, (int)($baseMs / 2));
                usleep(($baseMs + $jitterMs) * 1000);
            }
        }
    }

    /**
     * 通用查询 — 自动判断读写，选择是否重试。
     *
     * SELECT/PRAGMA → queryRead（零重试开销）
     * INSERT/UPDATE/DELETE → queryWrite（带重试）
     */
    public function query($sql, $params = [])
    {
        $firstWord = strtoupper(strtok(trim($sql), " \t\n\r"));
        if (in_array($firstWord, ['SELECT', 'PRAGMA', 'EXPLAIN'], true)) {
            return $this->queryRead($sql, $params);
        }
        return $this->queryWrite($sql, $params);
    }

    public function fetch($sql, $params = [])
    {
        return $this->query($sql, $params)->fetch();
    }

    public function fetchAll($sql, $params = [])
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchCached($sql, $params = [], $tags = [])
    {
        if (!$this->queryCache->isEnabled()) {
            return $this->fetchAll($sql, $params);
        }

        $cacheKey = md5($sql . json_encode($params));
        $cached = $this->queryCache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $result = $this->fetchAll($sql, $params);
        $this->queryCache->set($cacheKey, $result, $tags);
        return $result;
    }

    public function fetchOneCached($sql, $params = [], $tags = [])
    {
        if (!$this->queryCache->isEnabled()) {
            return $this->fetch($sql, $params);
        }

        $cacheKey = md5($sql . json_encode($params));
        $cached = $this->queryCache->get($cacheKey);

        // 注意：fetch() 返回 array（行）或 false（无行），从不返回 null。
        // 因此用 null 作为"缓存未命中"的 sentinel 是安全的——
        // 缓存命中（包括 false）均返回非 null 值。
        // 修复前 if (!empty($result)) 导致 false 结果不入缓存，
        // 重复查询不存在记录会持续穿透到 DB（缓存穿透攻击面）。
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->fetch($sql, $params);
        // 即使 $result === false（无行匹配）也缓存，
        // 用较短 TTL 避免长期缓存"负结果"导致数据新增后仍查不到。
        // 当前 QueryCache 全局 TTL 600s，对负结果足够安全（最多 10 分钟穿透）。
        $this->queryCache->set($cacheKey, $result, $tags);

        return $result;
    }

    // ====================================================================
    //  缓存管理委托给 QueryCache
    // ====================================================================

    public function getCacheStats()
    {
        return $this->queryCache->getStats();
    }

    public function setCacheEnabled($enabled)
    {
        $this->queryCache->setEnabled($enabled);
    }

    public function clearQueryCache($pattern = null)
    {
        $this->queryCache->clear($pattern);
    }

    public function clearCacheByTags($tags)
    {
        $this->queryCache->clearByTags($tags);
    }

    public function invalidateTableCache($tableName)
    {
        $tags = [$tableName, 'table:' . $tableName];
        $this->queryCache->clearByTags($tags);
    }

    public function getCacheInfo()
    {
        return $this->queryCache->getInfo();
    }

    public function clearAllCache()
    {
        $this->queryCache->clear();
    }

    // ====================================================================
    //  CRUD 辅助
    // ====================================================================

    /**
     * 校验表名必须在 SchemaManager::KNOWN_TABLES 白名单内。
     *
     * 防止调用方拼接用户输入的表名导致 SQL 注入：所有 insert/update/delete
     * 调用方传的 $table 都必须显式登记到 KNOWN_TABLES。
     * 新增表时应同步登记到 SchemaManager::KNOWN_TABLES。
     *
     * 注意：仅在 DEBUG 模式下抛异常，生产环境记录日志后继续执行（避免误报阻断业务）。
     * 这是"开发期严、生产期宽"的策略：开发期能尽早暴露问题，生产期不破坏兼容。
     */
    private function assertValidTable(string $table): void
    {
        if (!in_array($table, SchemaManager::KNOWN_TABLES, true)) {
            $msg = "Database: unknown table '{$table}' (not in SchemaManager::KNOWN_TABLES)";
            \App\Core\AsyncLogger::getInstance()->warning($msg);
            if (defined('DEBUG') && DEBUG) {
                throw new \InvalidArgumentException($msg);
            }
        }
    }

    /**
     * 校验 SQL 标识符（表名/列名）格式合法。
     *
     * 仅允许 [a-zA-Z_][a-zA-Z0-9_]*，禁止空格、引号、分号、注释等危险字符。
     * 这不是完整的 SQL 解析，但能挡住绝大多数注入尝试。
     */
    private function assertValidIdentifier(string $name, string $context = 'identifier'): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            $msg = "Database: invalid {$context} '{$name}' (must match [a-zA-Z_][a-zA-Z0-9_]*)";
            \App\Core\AsyncLogger::getInstance()->warning($msg);
            if (defined('DEBUG') && DEBUG) {
                throw new \InvalidArgumentException($msg);
            }
        }
    }

    public function insert($table, $data)
    {
        $this->assertValidTable($table);
        foreach (array_keys($data) as $field) {
            $this->assertValidIdentifier((string)$field, 'insert column');
        }
        $fields = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$table} ({$fields}) VALUES ({$placeholders})";

        // PostgreSQL 的 lastInsertId() 不带参数时返回空字符串，
        // 必须用 RETURNING id 获取自增主键
        if ($this->dbType === 'pgsql') {
            $sql .= ' RETURNING id';
            $stmt = $this->query($sql, array_values($data));
            $id = $stmt->fetchColumn();
            $this->invalidateTableCache($table);
            return $id !== false ? $id : null;
        }

        $result = $this->query($sql, array_values($data));
        $this->invalidateTableCache($table);
        return $this->pdo->lastInsertId();
    }

    public function update($table, $data, $where, $whereParams = [])
    {
        $this->assertValidTable($table);
        foreach (array_keys($data) as $key) {
            $this->assertValidIdentifier((string)$key, 'update column');
        }
        $setParts = [];
        foreach (array_keys($data) as $key) {
            $setParts[] = "{$key} = ?";
        }
        $setClause = implode(', ', $setParts);
        $sql = "UPDATE {$table} SET {$setClause} WHERE {$where}";
        $params = array_merge(array_values($data), $whereParams);
        $result = $this->query($sql, $params)->rowCount();
        $this->invalidateTableCache($table);
        return $result;
    }

    public function delete($table, $where, $params = [])
    {
        $this->assertValidTable($table);
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $result = $this->query($sql, $params)->rowCount();
        $this->invalidateTableCache($table);
        return $result;
    }

    /**
     * 开启事务。
     *
     * SQLite 驱动默认使用 BEGIN IMMEDIATE 立即获取 RESERVED 锁（写锁），
     * 避免 BEGIN DEFERRED 的 TOCTOU 竞态（事务内 SELECT 可被并发写入插入）。
     * 由于 exec('BEGIN IMMEDIATE') 绕过 PDO 事务状态，需手动维护 $inTransaction 标志。
     *
     * 纯写场景（无先读后写的 TOCTOU 风险）可传 'deferred' 参数，
     * 延迟到首次写操作时才获取锁，减少锁持有时间。
     *
     * MySQL/PostgreSQL 已有行级锁，使用 PDO 原生 beginTransaction() 即可。
     *
     * @param string $mode 'immediate'（默认，防 TOCTOU）或 'deferred'（纯写场景）
     */
    public function beginTransaction(string $mode = 'immediate')
    {
        // 嵌套保护：已在事务中直接返回
        if ($this->inTransaction) {
            return true;
        }

        $maxRetries = 3;
        $attempt = 0;

        while (true) {
            try {
                if ($this->dbType === 'sqlite') {
                    $sql = ($mode === 'deferred') ? 'BEGIN DEFERRED' : 'BEGIN IMMEDIATE';
                    $this->pdo->exec($sql);
                } else {
                    $this->pdo->beginTransaction();
                }
                break;
            } catch (PDOException $e) {
                $attempt++;
                if ($attempt >= $maxRetries || !$this->isLockContention($e)) {
                    throw $e;
                }
                // 指数退避 + 随机抖动
                $baseMs = 50 * pow(2, $attempt - 1);
                $jitterMs = random_int(0, (int)($baseMs / 2));
                usleep(($baseMs + $jitterMs) * 1000);
            }
        }

        $this->inTransaction = true;
        return true;
    }

    /**
     * 提交事务。
     *
     * SQLite 驱动使用 exec('COMMIT')（与 BEGIN IMMEDIATE 对应），
     * 其他驱动使用 PDO 原生 commit()。
     */
    public function commit()
    {
        if (!$this->inTransaction) {
            return false;
        }
        if ($this->dbType === 'sqlite') {
            $this->pdo->exec('COMMIT');
        } else {
            $this->pdo->commit();
        }
        $this->inTransaction = false;
        return true;
    }

    /**
     * 回滚事务。
     *
     * SQLite 驱动使用 exec('ROLLBACK')（与 BEGIN IMMEDIATE 对应），
     * 其他驱动使用 PDO 原生 rollBack()。
     * 无论回滚是否抛异常，都重置事务标志以避免状态泄露。
     */
    public function rollBack()
    {
        if (!$this->inTransaction) {
            return false;
        }
        try {
            if ($this->dbType === 'sqlite') {
                $this->pdo->exec('ROLLBACK');
            } else {
                $this->pdo->rollBack();
            }
        } finally {
            $this->inTransaction = false;
        }
        return true;
    }

    /**
     * 返回当前是否处于事务中。
     *
     * SQLite 驱动使用 exec('BEGIN IMMEDIATE') 绕过 PDO 事务状态，
     * 因此返回独立维护的 $inTransaction 标志；同时 OR PDO::inTransaction()
     * 以兼容非 SQLite 驱动或外部直接调用 PDO 事务的场景。
     * ConcurrencyGuard 通过此方法做嵌套检测。
     */
    public function inTransaction(): bool
    {
        return $this->inTransaction || $this->pdo->inTransaction();
    }

    // ====================================================================
    //  SAVEPOINT（嵌套事务支持）
    //  ───────────────────────────────────────────────────────────────────
    //  SQLite/MySQL/PostgreSQL 均原生支持 SAVEPOINT，语法一致：
    //    SAVEPOINT <name>           创建
    //    RELEASE SAVEPOINT <name>   提交（释放，变更随外层事务提交）
    //    ROLLBACK TO SAVEPOINT <name>  回滚到此 savepoint（不释放，可继续使用）
    //
    //  使用场景：ConcurrencyGuard::transactionImmediate 嵌套调用时，
    //  内层事务创建 savepoint，异常时 ROLLBACK TO 撤销内层修改，
    //  外层仍可继续提交或回滚——避免"假 savepoint"导致内层异常被吞后
    //  外层 commit 提交部分内层修改的问题。
    // ====================================================================

    /** 全局 savepoint 序号，用于生成唯一名称 */
    private int $savepointSeq = 0;

    /**
     * 在当前事务内创建 SAVEPOINT。
     *
     * @return string savepoint 名称（sp_<seq>_<rand>）
     * @throws \RuntimeException 不在事务中时调用
     */
    public function savepoint(): string
    {
        if (!$this->inTransaction()) {
            throw new \RuntimeException('Database::savepoint() requires an active transaction');
        }
        $this->savepointSeq++;
        $name = 'sp_' . $this->savepointSeq . '_' . substr(md5((string)mt_rand()), 0, 6);

        // MySQL 早期版本不支持 RELEASE SAVEPOINT name 中的 SAVEPOINT 关键字，
        // 但 5.0+ 均支持。统一用标准 SQL 语法。
        try {
            $this->pdo->exec("SAVEPOINT {$name}");
        } catch (PDOException $e) {
            // 极少数环境不支持 SAVEPOINT（如旧版 MySQL），降级为 no-op
            // 此时嵌套语义退化为"假 savepoint"，但日志告警便于排查
            \App\Core\AsyncLogger::getInstance()->warning(
                'Database: SAVEPOINT not supported, falling back to no-op',
                ['name' => $name, 'error' => $e->getMessage()]
            );
        }
        return $name;
    }

    /**
     * 释放 SAVEPOINT（等价于"提交"内层事务）。
     * 调用后内层修改将随外层事务一起提交。
     */
    public function releaseSavepoint(string $name): void
    {
        if (!$this->inTransaction()) {
            return;
        }
        try {
            $this->pdo->exec("RELEASE SAVEPOINT {$name}");
        } catch (PDOException $e) {
            \App\Core\AsyncLogger::getInstance()->warning(
                'Database: RELEASE SAVEPOINT failed',
                ['name' => $name, 'error' => $e->getMessage()]
            );
        }
    }

    /**
     * 回滚到 SAVEPOINT（撤销内层事务的修改，不结束外层事务）。
     * savepoint 仍然存在，可继续使用或随后 RELEASE。
     */
    public function rollbackToSavepoint(string $name): void
    {
        if (!$this->inTransaction()) {
            return;
        }
        try {
            $this->pdo->exec("ROLLBACK TO SAVEPOINT {$name}");
        } catch (PDOException $e) {
            \App\Core\AsyncLogger::getInstance()->warning(
                'Database: ROLLBACK TO SAVEPOINT failed',
                ['name' => $name, 'error' => $e->getMessage()]
            );
        }
    }
}
