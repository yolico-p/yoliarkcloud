<?php

namespace App\Core;

use PDO;
use PDOException;

/**
 * SQLite 并发与性能中间层
 *
 * 目标：让 SQLite 达到与 MySQL 一致的并发与性能体验。
 *
 * 解决的核心缺陷：
 *
 * 【并发缺陷】
 * - TOCTOU（检查-使用竞态）：BEGIN DEFERRED 不持锁，事务内 SELECT 可被并发写入插入
 * - 无行级锁：SQLite 只有数据库级写锁，无法做 SELECT FOR UPDATE
 * - 无原生咨询锁：需用文件锁模拟
 *
 * 【性能缺陷】
 * - Schema 每请求执行 DDL：多 fpm worker 并发建表，写锁竞争
 * - WAL 检查点不可控：默认 1000 页自动检查点可能卡顿
 * - 无 ANALYZE 统计：查询计划器缺少统计信息
 *
 * 【事务状态管理缺陷】
 * - exec('BEGIN IMMEDIATE') 绕过 PDO 事务状态，inTransaction() 返回 false
 * - 导致 Database::rollBack() 失效，Database::beginTransaction() 嵌套冲突
 *
 * 设计原则：
 * 1. 不修改 Database / Model / Service 的现有接口，完全向后兼容
 * 2. 维护独立的事务状态标志，与 PDO 事务状态解耦
 * 3. 跨数据库适配（SQLite/MySQL/PostgreSQL），非 SQLite 自动降级为普通语义
 */
class ConcurrencyGuard
{
    private static $instance = null;
    private $db;
    private $dbType;
    private $schemaFlagFile;
    private $schemaVersionFile;

    /**
     * Guard 嵌套深度计数器。
     *
     * 历史问题：原本维护独立的 $inGuardTransaction bool 标志，与 Database::$inTransaction
     * 互相不感知，混用会导致 BEGIN IMMEDIATE 嵌套失败（"cannot start a transaction
     * within a transaction"）或 commit/rollback 错位。
     *
     * 现在 transactionImmediate() 委托 Database::beginTransaction/commit/rollBack，
     * 共享 Database 层的 $inTransaction 标志，本计数器仅用于追踪嵌套深度，
     * 决定内层调用是否真正提交/回滚（savepoint 语义）。
     *
     * 注意：SQLite 嵌套事务需 SAVEPOINT 实现真正的部分回滚（见 Task #12），
     * 当前嵌套层直接执行 $fn()，不真正 savepoint。
     */
    private int $guardDepth = 0;

    /** Schema 版本：升级 schema 时递增，触发重新初始化 */
    public const SCHEMA_VERSION = 8;

    private function __construct()
    {
        $this->db = Database::getInstance();
        $this->dbType = $this->db->getDbType();
        $this->schemaFlagFile = DATA_PATH . DIRECTORY_SEPARATOR . '.schema_initialized';
        $this->schemaVersionFile = DATA_PATH . DIRECTORY_SEPARATOR . '.schema_version';
    }

    public static function getInstance(): static
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    /**
     * 重置单例（仅用于安装失败回滚，配合 Database::resetInstance() 使用）。
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    // ========================================================================
    //  Schema 初始化标志（解决：SchemaManager 每请求执行 DDL 的性能竞态）
    // ========================================================================

    /**
     * 判断 Schema 是否已初始化且版本匹配。
     *
     * 原理：首次请求执行 DDL 后写入标志文件，后续请求直接跳过。
     * 升级 schema 时递增 SCHEMA_VERSION，标志失效，触发一次性重新初始化。
     *
     * 性能影响：将每请求 40+ 次 exec(DDL) 降为 0 次，消除 fpm worker 间的写锁竞争。
     */
    public function isSchemaInitialized(): bool
    {
        if (!file_exists($this->schemaFlagFile) || !file_exists($this->schemaVersionFile)) {
            return false;
        }
        $version = @file_get_contents($this->schemaVersionFile);
        return $version !== false && (int)trim($version) >= self::SCHEMA_VERSION;
    }

    /**
     * 标记 Schema 已完成初始化。
     *
     * 使用临时文件 + rename 保证原子性，避免并发请求读到半写状态。
     */
    public function markSchemaInitialized(): void
    {
        $tmpFlag = $this->schemaFlagFile . '.tmp';
        $tmpVersion = $this->schemaVersionFile . '.tmp';

        @file_put_contents($tmpFlag, (string)time(), LOCK_EX);
        @file_put_contents($tmpVersion, (string)self::SCHEMA_VERSION, LOCK_EX);

        // rename 在同一文件系统上是原子的
        @rename($tmpFlag, $this->schemaFlagFile);
        @rename($tmpVersion, $this->schemaVersionFile);
    }

    /**
     * 强制下次请求重新执行 Schema 初始化与全部增量迁移。
     *
     * 通过删除标志文件使 isSchemaInitialized() 返回 false，
     * 下次 initTables() 将重跑基础 DDL 并应用所有未记录的迁移。
     */
    public function forceMigration(): void
    {
        @unlink($this->schemaFlagFile);
        @unlink($this->schemaVersionFile);
    }

    // ========================================================================
    //  立即写锁事务（解决：TOCTOU 竞态）
    // ========================================================================

    /**
     * 立即获取写锁的事务。
     *
     * SQLite 的 BEGIN DEFERRED（PDO 默认）在首次写时才获取写锁，
     * 导致事务内的 SELECT 检查可能被其他写事务插入（TOCTOU）。
     *
     * BEGIN IMMEDIATE 立即获取 RESERVED 锁（写锁），串行化所有写操作，
     * 使事务内的 SELECT-then-UPDATE 具有真正的原子性，等价于 MySQL 的
     * SELECT FOR UPDATE + UPDATE。
     *
     * 事务状态管理与嵌套：
     * - 外层：Database::beginTransaction/commit/rollBack，共享 Database 层
     *   $inTransaction 标志（与 Database::beginTransaction 用户共用同一状态）
     * - 嵌套：创建 SAVEPOINT，异常时 ROLLBACK TO 撤销内层修改
     *   （真正的 savepoint 语义，避免"假 savepoint"导致内层异常被吞后
     *   外层 commit 提交部分内层修改的问题）
     * - guardDepth 追踪嵌套深度，仅最外层真正 BEGIN/COMMIT
     *
     * MySQL/PostgreSQL 已有行级锁，使用普通事务即可。
     *
     * @param callable $fn 事务体，返回值作为事务结果
     * @return mixed 事务体的返回值
     * @throws \Throwable 事务失败时抛出
     */
    public function transactionImmediate(callable $fn): mixed
    {
        // 嵌套场景：Database::inTransaction() 同时反映 PDO 原生事务状态与
        // Database 自己维护的 $inTransaction 标志，覆盖两种入口
        if ($this->db->inTransaction()) {
            $this->guardDepth++;
            $sp = $this->db->savepoint();
            try {
                $result = $fn();
                $this->db->releaseSavepoint($sp);
                return $result;
            } catch (\Throwable $e) {
                // 真正的 savepoint：撤销内层修改但不结束外层事务，
                // 外层可继续执行（如 catch 后吞异常继续）或随后回滚
                $this->db->rollbackToSavepoint($sp);
                $this->db->releaseSavepoint($sp);
                throw $e;
            } finally {
                $this->guardDepth = max(0, $this->guardDepth - 1);
            }
        }

        // Database::beginTransaction 内部已实现 BEGIN IMMEDIATE + 锁竞争重试
        $this->db->beginTransaction();
        $this->guardDepth = 1;

        try {
            $result = $fn();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            try {
                $this->db->rollBack();
            } catch (\PDOException $ignore) {
                // 回滚失败不影响异常传播
            }
            throw $e;
        } finally {
            $this->guardDepth = 0;
        }
    }

    /**
     * DEFERRED 事务 — 适用于纯写场景（无 TOCTOU 风险）。
     *
     * 与 transactionImmediate() 的区别：
     * - IMMEDIATE：立即获取 RESERVED 锁，阻塞其他写者，但保证事务内 SELECT 一致性
     * - DEFERRED：首次写时才获取锁，减少锁持有时间，适合纯 INSERT/UPDATE/DELETE
     *
     * 使用场景：
     * - 批量 DELETE（已知 ID，无需先查后删）
     * - 纯 UPDATE（已知条件和值）
     * - 批量 INSERT（数据已准备好，无需检查唯一性——唯一索引是最后防线）
     */
    public function transactionDeferred(callable $fn): mixed
    {
        if ($this->db->inTransaction()) {
            // 已在事务中，直接执行（无论外层是 IMMEDIATE 还是 DEFERRED）
            return $fn();
        }

        $this->db->beginTransaction('deferred');

        try {
            $result = $fn();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            try {
                $this->db->rollBack();
            } catch (\PDOException $ignore) {
            }
            throw $e;
        }
    }

    // ========================================================================
    //  UPSERT（解决：delete-insert 竞态）
    // ========================================================================

    /**
     * 插入或替换（UPSERT）。
     *
     * 解决 recent_access 等场景的 delete-insert 竞态：
     * 两个并发请求都 delete（0 行）后都 insert，产生重复记录。
     *
     * - SQLite 3.24+: INSERT ... ON CONFLICT DO UPDATE
     * - MySQL: INSERT ... ON DUPLICATE KEY UPDATE
     * - PostgreSQL: INSERT ... ON CONFLICT DO UPDATE
     *
     * @param string $table 表名
     * @param array $data 数据
     * @param array $uniqueKeys 唯一键
     * @param array $updateFields 冲突时更新的字段（空则全部更新）
     * @return int 受影响行数
     */
    public function upsert(
        string $table,
        array $data,
        array $uniqueKeys = [],
        array $updateFields = []
    ): int {
        $fields = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $params = array_values($data);

        $updateKeys = !empty($updateFields)
            ? $updateFields
            : array_diff(array_keys($data), $uniqueKeys);

        switch ($this->dbType) {
            case 'sqlite':
                if (!empty($uniqueKeys) && version_compare($this->getSqliteVersion(), '3.24', '>=')) {
                    $conflictCols = implode(', ', $uniqueKeys);
                    if (!empty($updateKeys)) {
                        $updateParts = array_map(fn($k) => "{$k} = excluded.{$k}", $updateKeys);
                        $sql = "INSERT INTO {$table} ({$fields}) VALUES ({$placeholders}) "
                             . "ON CONFLICT({$conflictCols}) DO UPDATE SET " . implode(', ', $updateParts);
                    } else {
                        $sql = "INSERT INTO {$table} ({$fields}) VALUES ({$placeholders}) "
                             . "ON CONFLICT({$conflictCols}) DO NOTHING";
                    }
                } else {
                    $sql = "INSERT OR REPLACE INTO {$table} ({$fields}) VALUES ({$placeholders})";
                }
                break;

            case 'mysql':
                if (!empty($updateKeys)) {
                    $updateParts = array_map(fn($k) => "{$k} = VALUES({$k})", $updateKeys);
                    $sql = "INSERT INTO {$table} ({$fields}) VALUES ({$placeholders}) "
                         . "ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts);
                } else {
                    $sql = "INSERT IGNORE INTO {$table} ({$fields}) VALUES ({$placeholders})";
                }
                break;

            case 'pgsql':
                if (!empty($uniqueKeys)) {
                    $conflictCols = implode(', ', $uniqueKeys);
                    if (!empty($updateKeys)) {
                        $updateParts = array_map(fn($k) => "{$k} = EXCLUDED.{$k}", $updateKeys);
                        $sql = "INSERT INTO {$table} ({$fields}) VALUES ({$placeholders}) "
                             . "ON CONFLICT({$conflictCols}) DO UPDATE SET " . implode(', ', $updateParts);
                    } else {
                        $sql = "INSERT INTO {$table} ({$fields}) VALUES ({$placeholders}) "
                             . "ON CONFLICT({$conflictCols}) DO NOTHING";
                    }
                } else {
                    $sql = "INSERT INTO {$table} ({$fields}) VALUES ({$placeholders}) ON CONFLICT DO NOTHING";
                }
                break;

            default:
                $sql = "INSERT OR REPLACE INTO {$table} ({$fields}) VALUES ({$placeholders})";
        }

        $result = $this->db->query($sql, $params)->rowCount();
        $this->db->invalidateTableCache($table);
        return $result;
    }

    // ========================================================================
    //  带文件锁的操作包装（解决：FTS5 重建等跨进程竞态）
    // ========================================================================

    /**
     * 跨进程互斥锁包装操作。
     *
     * 根据数据库类型分流：
     * - sqlite: flock 文件锁（原实现）
     * - mysql:  GET_LOCK / RELEASE_LOCK 命名锁（会话级，请求结束自动释放）
     * - pgsql:  pg_try_advisory_lock / pg_advisory_unlock 咨询锁（进程级）
     *
     * 用于 FTS5 重建等不需要数据库事务但需要进程互斥的场景。
     * 锁获取失败或异常时降级为直接执行 $fn()（与原 flock 降级语义一致）。
     *
     * @param string $lockKey 锁键
     * @param callable $fn 操作体
     * @param int $timeoutMs 锁等待超时（毫秒），0 表示非阻塞
     * @return mixed 操作体的返回值
     */
    public function withFileLock(string $lockKey, callable $fn, int $timeoutMs = 0): mixed
    {
        if ($this->dbType === 'mysql') {
            return $this->withMysqlLock($lockKey, $fn, $timeoutMs);
        }
        if ($this->dbType === 'pgsql') {
            return $this->withPgsqlLock($lockKey, $fn, $timeoutMs);
        }
        return $this->withFileLockNative($lockKey, $fn, $timeoutMs);
    }

    /**
     * 原生 flock 文件锁实现（SQLite 场景）。
     *
     * 锁文件持久存在，下次 fopen('c+') 会复用，避免 unlink 竞态。
     */
    private function withFileLockNative(string $lockKey, callable $fn, int $timeoutMs = 0): mixed
    {
        $lockFile = DATA_PATH . DIRECTORY_SEPARATOR . 'guard_' . md5($lockKey) . '.lock';
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            return $fn(); // 降级
        }

        $lockAcquired = false;
        if ($timeoutMs <= 0) {
            $lockAcquired = flock($fp, LOCK_EX | LOCK_NB);
        } else {
            $deadline = microtime(true) + $timeoutMs / 1000;
            while (microtime(true) < $deadline) {
                if (flock($fp, LOCK_EX | LOCK_NB)) {
                    $lockAcquired = true;
                    break;
                }
                usleep(10000); // 10ms
            }
            if (!$lockAcquired) {
                $lockAcquired = flock($fp, LOCK_EX);
            }
        }

        if (!$lockAcquired) {
            fclose($fp);
            return $fn();
        }

        try {
            $result = $fn();
        } finally {
            // 不删除锁文件，避免 flock+unlink 竞态：
            // unlink 后其他进程 fopen('c+') 创建新文件，与仍持锁的旧 fd 失去互斥关系，
            // 导致两个进程同时进入临界区。锁文件持久存在无害，fopen('c+') 复用同一文件。
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        return $result;
    }

    /**
     * MySQL 命名锁实现（GET_LOCK / RELEASE_LOCK）。
     *
     * - 命名锁 key：md5($lockKey) 前 64 字符（MySQL GET_LOCK 上限 64 字符）
     * - 超时：$timeoutMs <= 0 传 0（非阻塞），否则 max(1, ceil($timeoutMs/1000)) 秒
     * - GET_LOCK 返回 1=成功, 0=超时, NULL=错误
     *
     * 注意：GET_LOCK 是会话级锁，PHP 请求结束自动释放；持久连接下需显式
     * RELEASE_LOCK，所以必须在 finally 中释放。
     */
    private function withMysqlLock(string $lockKey, callable $fn, int $timeoutMs = 0): mixed
    {
        $key = substr(md5($lockKey), 0, 64);
        $timeoutSec = $timeoutMs <= 0 ? 0 : max(1, (int)ceil($timeoutMs / 1000));

        try {
            $pdo = $this->db->getPdo();
            $stmt = $pdo->query("SELECT GET_LOCK('{$key}', {$timeoutSec})");
            $result = $stmt ? $stmt->fetchColumn() : false;
            // GET_LOCK: 1=成功, 0=超时, NULL=错误
            if ($result !== 1 && $result !== '1') {
                return $fn(); // 降级
            }
        } catch (\Throwable $e) {
            return $fn(); // 降级
        }

        try {
            return $fn();
        } finally {
            try {
                $this->db->getPdo()->query("SELECT RELEASE_LOCK('{$key}')");
            } catch (\Throwable $e) {
                // 释放失败忽略
            }
        }
    }

    /**
     * PostgreSQL 咨询锁实现（pg_try_advisory_lock / pg_advisory_unlock）。
     *
     * - 咨询锁 key 必须是 bigint（int64），用 crc32($lockKey) 转为正整数
     *   （crc32 返回 0-4294967295 无符号 32 位，PG advisory lock 接受 int64）
     * - 非阻塞（$timeoutMs <= 0）：pg_try_advisory_lock 返回 t/f
     * - 阻塞（$timeoutMs > 0）：循环 pg_try_advisory_lock + usleep(10000)，尊重 timeout
     *
     * 咨询锁是进程级锁，PG 连接断开自动释放；持久连接下需显式释放。
     */
    private function withPgsqlLock(string $lockKey, callable $fn, int $timeoutMs = 0): mixed
    {
        $intKey = sprintf('%d', crc32($lockKey));
        $acquired = false;
        $pdo = null;

        try {
            $pdo = $this->db->getPdo();
            if ($timeoutMs <= 0) {
                $stmt = $pdo->query("SELECT pg_try_advisory_lock({$intKey})");
                $row = $stmt ? $stmt->fetchColumn() : false;
                $acquired = ($row === true || $row === 't' || $row === 1 || $row === '1');
            } else {
                $deadline = microtime(true) + $timeoutMs / 1000;
                while (microtime(true) < $deadline) {
                    $stmt = $pdo->query("SELECT pg_try_advisory_lock({$intKey})");
                    $row = $stmt ? $stmt->fetchColumn() : false;
                    if ($row === true || $row === 't' || $row === 1 || $row === '1') {
                        $acquired = true;
                        break;
                    }
                    usleep(10000); // 10ms
                }
            }
        } catch (\Throwable $e) {
            return $fn(); // 降级
        }

        if (!$acquired) {
            return $fn(); // 降级（超时）
        }

        try {
            return $fn();
        } finally {
            try {
                $pdo->query("SELECT pg_advisory_unlock({$intKey})");
            } catch (\Throwable $e) {
                // 释放失败忽略
            }
        }
    }

    // ========================================================================
    //  原子计数器递增（解决：分享下载计数 TOCTOU）
    // ========================================================================

    /**
     * 带上限检查的原子计数器递增。
     *
     * 用单条条件 UPDATE 原子完成"检查未达上限 + 递增 + 达上限时停用"。
     *
     * @param string $table 表名
     * @param string $counterField 计数字段名
     * @param string $where WHERE 条件
     * @param array $params WHERE 参数
     * @param int $maxValue 最大值（0 表示不限）
     * @param string $activeField 启用状态字段（达上限时置 0）
     * @return array ['incremented' => bool, 'new_value' => int, 'reason' => string]
     */
    public function incrementWithLimit(
        string $table,
        string $counterField,
        string $where,
        array $params = [],
        int $maxValue = 0,
        string $activeField = ''
    ): array {
        $setParts = ["{$counterField} = {$counterField} + 1"];

        $condition = "{$counterField} IS NOT NULL";
        if ($maxValue > 0) {
            $condition .= " AND {$counterField} < {$maxValue}";
            if ($activeField) {
                $setParts[] = "{$activeField} = CASE WHEN {$counterField} + 1 >= {$maxValue} THEN 0 ELSE {$activeField} END";
            }
        }

        $setClause = implode(', ', $setParts);
        $sql = "UPDATE {$table} SET {$setClause} WHERE {$where} AND ({$condition})";

        $stmt = $this->db->query($sql, $params);
        $rows = $stmt->rowCount();
        $this->db->invalidateTableCache($table);

        if ($rows === 0) {
            $row = $this->db->fetch(
                "SELECT {$counterField} as cnt FROM {$table} WHERE {$where}",
                $params
            );
            $current = $row ? (int)$row['cnt'] : 0;
            return [
                'incremented' => false,
                'new_value' => $current,
                'reason' => $row ? 'limit_reached' : 'not_found',
            ];
        }

        $row = $this->db->fetch(
            "SELECT {$counterField} as cnt FROM {$table} WHERE {$where}",
            $params
        );
        return [
            'incremented' => true,
            'new_value' => $row ? (int)$row['cnt'] : 0,
            'reason' => 'ok',
        ];
    }

    // ========================================================================
    //  WAL 检查点与数据库维护（解决：WAL 文件膨胀与读取性能退化）
    // ========================================================================

    /**
     * 触发 WAL 检查点。
     *
     * SQLite WAL 模式下，写入先追加到 -wal 文件，检查点时合并回主库。
     * 默认每 1000 页自动检查点，但可能在高并发读取时造成卡顿。
     *
     * - PASSIVE（默认）：不等待，尽可能多的合并
     * - FULL：等待所有读者完成，合并后重置 WAL
     * - RESTART：类似 FULL，但阻塞新读者直到 WAL 重置
     * - TRUNCATE：RESTART + 截断 WAL 文件到 0
     *
     * @param string $mode PASSIVE|FULL|RESTART|TRUNCATE
     * @return bool 是否成功
     */
    public function walCheckpoint(string $mode = 'PASSIVE'): bool
    {
        if ($this->dbType !== 'sqlite') {
            return true;
        }

        $validModes = ['PASSIVE', 'FULL', 'RESTART', 'TRUNCATE'];
        $mode = strtoupper($mode);
        if (!in_array($mode, $validModes)) {
            $mode = 'PASSIVE';
        }

        try {
            $this->db->getPdo()->exec("PRAGMA wal_checkpoint({$mode})");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * 更新查询统计信息（ANALYZE）。
     *
     * SQLite 的查询计划器依赖统计信息选择最优索引。
     * 大量写入后统计信息会过时，导致查询计划次优。
     *
     * MySQL/PostgreSQL 也有 ANALYZE 命令。
     *
     * @param string $table 指定表（空则全部）
     * @return bool
     */
    public function analyze(string $table = ''): bool
    {
        try {
            if ($this->dbType === 'sqlite' || $this->dbType === 'pgsql') {
                $sql = $table ? "ANALYZE {$table}" : "ANALYZE";
            } else {
                $sql = $table ? "ANALYZE TABLE {$table}" : "ANALYZE TABLE";
            }
            $this->db->getPdo()->exec($sql);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * 获取 SQLite WAL 状态信息。
     *
     * 用于监控 WAL 文件大小、检查点频率等。
     *
     * @return array WAL 状态
     */
    public function getWalStatus(): array
    {
        if ($this->dbType !== 'sqlite') {
            return ['supported' => false];
        }

        try {
            $pdo = $this->db->getPdo();
            $mode = $pdo->query('PRAGMA journal_mode')->fetchColumn();
            $walSize = 0;
            $walFile = DB_PATH . '-wal';
            if (file_exists($walFile)) {
                $walSize = filesize($walFile);
            }
            $shmFile = DB_PATH . '-shm';
            $shmSize = file_exists($shmFile) ? filesize($shmFile) : 0;

            // wal_checkpoint 状态：busy=0(空闲), 1(忙碌); log=WAL帧数; checkpointed=已检查点帧数
            $checkpointInfo = $pdo->query('PRAGMA wal_checkpoint')->fetch(\PDO::FETCH_NUM);

            return [
                'supported' => true,
                'journal_mode' => $mode,
                'wal_file_size' => $walSize,
                'shm_file_size' => $shmSize,
                'wal_frames' => $checkpointInfo[1] ?? 0,
                'wal_checkpointed' => $checkpointInfo[2] ?? 0,
            ];
        } catch (PDOException $e) {
            return ['supported' => true, 'error' => $e->getMessage()];
        }
    }

    // ========================================================================
    //  内部辅助
    // ========================================================================

    private ?string $sqliteVersion = null;

    private function getSqliteVersion(): string
    {
        if ($this->sqliteVersion === null) {
            try {
                $version = $this->db->getPdo()->query('SELECT sqlite_version()')->fetchColumn();
                $this->sqliteVersion = $version ?: '3.0';
            } catch (\PDOException $e) {
                $this->sqliteVersion = '3.0';
            }
        }
        return $this->sqliteVersion;
    }
}
