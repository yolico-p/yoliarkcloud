<?php

namespace App\Core;

use PDO;
use PDOException;

/**
 * SchemaManager - 独立的数据库 schema 管理类
 * 
 * 从 Database 中拆分而出，职责单一：
 * - 建表、加列、建索引、初始化全文搜索
 * - 跨数据库（SQLite / MySQL / PostgreSQL）的 schema 差异适配
 * 
 * Database 不再接触任何 CREATE / ALTER 逻辑
 */
class SchemaManager
{
    private PDO $pdo;
    private string $dbType;
    private array $dbConfig;

    /**
     * 当前 Schema 迁移版本号。
     *
     * 递增此值会触发增量迁移：已应用的版本会跳过，未应用的版本
     * 会按顺序执行对应的 migrate_v{N}() 方法。
     *
     * 注意：同时需要递增 ConcurrencyGuard::SCHEMA_VERSION，使旧库
     * 的标志文件失效，从而进入完整初始化路径执行迁移。
     */
    public const SCHEMA_VERSION = 8;

    /**
     * 已知表清单 — Database 层白名单校验依据。
     *
     * 任何 Database::insert/update/delete 调用必须传入此清单中的表名，
     * 防止调用方拼接用户输入的表名导致 SQL 注入。
     * 新增表时必须同步登记到此处。
     */
    public const KNOWN_TABLES = [
        'schema_migrations',
        'users',
        'files',
        'trash',
        'shares',
        'share_visits',
        'upload_tasks',
        'operation_logs',
        'recent_access',
        'ai_sessions',
        'ai_messages',
        'async_tasks',
        'rate_limit_buckets',
        'ai_agent_config',
        'inbox_files',
        'notifications',
        'ai_agent_progress',
        'ai_agent_todos',
    ];

    public function __construct(PDO $pdo, string $dbType, array $dbConfig = [])
    {
        $this->pdo = $pdo;
        $this->dbType = $dbType;
        $this->dbConfig = $dbConfig;
    }

    /**
     * 入口：初始化所有表结构�?     */
    public function initTables(): void
    {
        $guard = ConcurrencyGuard::getInstance();

        // Schema 已初始化：跳过 DDL（消除每请求 40+ 次 exec 的写锁竞争）
        // 仅执行低频幂等维护，保证数据一致性
        if ($guard->isSchemaInitialized()) {
            $this->rebuildFTS5IfStale();
            return;
        }

        // 完整路径：执行增量迁移
        $this->ensureMigrationsTable();
        $applied = $this->getAppliedMigrations();

        for ($v = 1; $v <= self::SCHEMA_VERSION; $v++) {
            if (in_array($v, $applied, true)) {
                continue;
            }
            $method = "migrate_v{$v}";
            if (method_exists($this, $method)) {
                $this->$method();
                $this->recordMigration($v);
            }
        }

        $guard->markSchemaInitialized();
    }

    // ========================================================================
    //  增量迁移机制
    // ========================================================================

    /**
     * 确保 schema_migrations 表存在。
     */
    private function ensureMigrationsTable(): void
    {
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
                version INTEGER PRIMARY KEY,
                applied_at INTEGER NOT NULL
            )");
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
    }

    /**
     * 获取已应用的迁移版本列表。
     *
     * @return int[]
     */
    private function getAppliedMigrations(): array
    {
        try {
            $stmt = $this->pdo->query("SELECT version FROM schema_migrations ORDER BY version");
            if ($stmt === false) {
                return [];
            }
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            return [];
        }
    }

    /**
     * 记录已应用的迁移版本。
     */
    private function recordMigration(int $version): void
    {
        try {
            if ($this->dbType === 'mysql') {
                $stmt = $this->pdo->prepare(
                    "INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE applied_at = VALUES(applied_at)"
                );
            } elseif ($this->dbType === 'pgsql') {
                $stmt = $this->pdo->prepare(
                    "INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)
                     ON CONFLICT (version) DO UPDATE SET applied_at = EXCLUDED.applied_at"
                );
            } else {
                $stmt = $this->pdo->prepare(
                    "INSERT OR REPLACE INTO schema_migrations (version, applied_at) VALUES (?, ?)"
                );
            }
            $stmt->execute([$version, time()]);
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
    }

    /**
     * v1：基础表结构与索引（原 initTables{SQLite,MySQL,PgSQL} + recent_access 唯一索引）。
     *
     * 所有 DDL 使用 IF NOT EXISTS，对已有库幂等。
     */
    private function migrate_v1(): void
    {
        switch ($this->dbType) {
            case 'sqlite':
                $this->initTablesSQLite();
                break;
            case 'mysql':
                $this->initTablesMySQL();
                break;
            case 'pgsql':
                $this->initTablesPgSQL();
                break;
        }
        $this->addRecentAccessUniqueIndex();
    }

    /**
     * v2：用户角色迁移、异步任务队列表、索引优化、移除废弃列。
     */
    private function migrate_v2(): void
    {
        // 用户角色迁移（一次性执行，移除原随机采样）
        $this->migrateExistingUsersToAdmin();

        // 创建异步任务队列表
        $this->createAsyncTasksTable();

        // 索引优化：删除冗余索引，添加复合索引
        $this->optimizeIndexesV2();

        // 移除废弃的 storage_used 列（如果数据库版本支持）
        $this->dropStorageUsedColumn();
    }

    /**
     * v3：清理冗余索引 + 补充 trash 表复合索引。
     *
     * 1. 删除 idx_shares_token（与 shares.share_token 内联 UNIQUE 约束自动创建的索引重复）
     * 2. 添加 idx_trash_user_path（覆盖 restoreChildren/permanentDelete 中
     *    WHERE user_id = ? AND original_path LIKE ? 的前缀扫描）
     */
    private function migrate_v3(): void
    {
        // 1. 删除冗余的 idx_shares_token
        //    shares.share_token 列已有内联 UNIQUE 约束，所有后端都会自动创建隐式唯一索引
        try {
            if ($this->dbType === 'mysql') {
                // MySQL 8.0+ 才支持 DROP INDEX IF EXISTS，故先查询
                $stmt = $this->pdo->query("SHOW INDEX FROM shares WHERE Key_name = 'idx_shares_token'");
                if ($stmt !== false && $stmt->rowCount() > 0) {
                    $this->pdo->exec("DROP INDEX idx_shares_token ON shares");
                }
            } else {
                // SQLite / PostgreSQL 均支持 DROP INDEX IF EXISTS
                $this->pdo->exec("DROP INDEX IF EXISTS idx_shares_token");
            }
        } catch (PDOException $e) {
            error_log("[SchemaManager] migrate_v3 drop idx_shares_token: " . $e->getMessage());
        }

        // 2. 添加 trash 表 (user_id, original_path) 复合索引
        //    restoreChildren / permanentDelete / getUniqueRestorePath 都按
        //    user_id + original_path LIKE 前缀查询，原 idx_trash_user 仅覆盖 user_id
        try {
            if ($this->dbType === 'mysql') {
                $stmt = $this->pdo->query("SHOW INDEX FROM trash WHERE Key_name = 'idx_trash_user_path'");
                if ($stmt === false || $stmt->rowCount() === 0) {
                    $this->pdo->exec("CREATE INDEX idx_trash_user_path ON trash(user_id, original_path(255))");
                }
            } else {
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_trash_user_path ON trash(user_id, original_path)");
            }
        } catch (PDOException $e) {
            error_log("[SchemaManager] migrate_v3 create idx_trash_user_path: " . $e->getMessage());
        }
    }

    /**
     * v4：修复 files 表中 path_hash 为 NULL 或空字符串的历史记录。
     *
     * 旧版代码可能通过某些路径插入 path_hash 为 NULL 的记录，
     * 导致后续 UPDATE 的 ELSE 子句保留 NULL 值，触发 NOT NULL 约束失败。
     * 此迁移用 filepath 的 md5 重新填充。
     *
     * 注意：SQLite 无内置 md5() 函数，需用 PHP 侧逐行修复。
     */
    private function migrate_v4(): void
    {
        try {
            if ($this->dbType === 'sqlite') {
                // SQLite 没有 md5() 内置函数，用 PHP 侧修复
                $stmt = $this->pdo->prepare("SELECT id, filepath FROM files WHERE path_hash IS NULL OR path_hash = ''");
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $updateStmt = $this->pdo->prepare("UPDATE files SET path_hash = ? WHERE id = ?");
                foreach ($rows as $row) {
                    $updateStmt->execute([md5($row['filepath']), $row['id']]);
                }
            } elseif ($this->dbType === 'mysql') {
                $this->pdo->exec(
                    "UPDATE files SET path_hash = MD5(filepath) WHERE path_hash IS NULL OR path_hash = ''"
                );
            } elseif ($this->dbType === 'pgsql') {
                $this->pdo->exec(
                    "UPDATE files SET path_hash = md5(filepath) WHERE path_hash IS NULL OR path_hash = ''"
                );
            }
        } catch (PDOException $e) {
            error_log("[SchemaManager] migrate_v4 repair path_hash: " . $e->getMessage());
        }
    }

    /**
     * v5：文件信箱表。
     */
    private function migrate_v5(): void
    {
        try {
            if ($this->dbType === 'sqlite') {
                $this->pdo->exec("CREATE TABLE IF NOT EXISTS inbox_files (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    filename TEXT NOT NULL,
                    filepath TEXT NOT NULL,
                    filesize INTEGER DEFAULT 0,
                    file_type TEXT DEFAULT '',
                    mime_type TEXT DEFAULT '',
                    sender_name TEXT DEFAULT '',
                    sender_message TEXT DEFAULT '',
                    inbox_token TEXT NOT NULL,
                    created_at INTEGER NOT NULL,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )");
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_inbox_user ON inbox_files(user_id, created_at)");
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_inbox_token ON inbox_files(inbox_token)");
            } elseif ($this->dbType === 'mysql') {
                $this->pdo->exec("CREATE TABLE IF NOT EXISTS inbox_files (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    filename VARCHAR(500) NOT NULL,
                    filepath VARCHAR(1000) NOT NULL,
                    filesize BIGINT DEFAULT 0,
                    file_type VARCHAR(50) DEFAULT '',
                    mime_type VARCHAR(200) DEFAULT '',
                    sender_name VARCHAR(100) DEFAULT '',
                    sender_message TEXT,
                    inbox_token VARCHAR(64) NOT NULL,
                    created_at INT NOT NULL,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX idx_inbox_user (user_id, created_at),
                    INDEX idx_inbox_token (inbox_token)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } elseif ($this->dbType === 'pgsql') {
                $this->pdo->exec("CREATE TABLE IF NOT EXISTS inbox_files (
                    id SERIAL PRIMARY KEY,
                    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    filename VARCHAR(500) NOT NULL,
                    filepath VARCHAR(1000) NOT NULL,
                    filesize BIGINT DEFAULT 0,
                    file_type VARCHAR(50) DEFAULT '',
                    mime_type VARCHAR(200) DEFAULT '',
                    sender_name VARCHAR(100) DEFAULT '',
                    sender_message TEXT DEFAULT '',
                    inbox_token VARCHAR(64) NOT NULL,
                    created_at INTEGER NOT NULL
                )");
                $this->createIndex('idx_inbox_user', 'inbox_files', 'user_id, created_at');
                $this->createIndex('idx_inbox_token', 'inbox_files', 'inbox_token');
            }
        } catch (PDOException $e) {
            error_log("[SchemaManager] migrate_v5: " . $e->getMessage());
        }
    }

    /**
     * v6：通知表 + AI Agent 后台任务进度表。
     */
    private function migrate_v6(): void
    {
        try {
            if ($this->dbType === 'sqlite') {
                $this->pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    type TEXT NOT NULL,
                    title TEXT NOT NULL,
                    body TEXT DEFAULT '',
                    related_id TEXT DEFAULT '',
                    is_read INTEGER DEFAULT 0,
                    created_at INTEGER NOT NULL
                )");
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, is_read, created_at)");
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_notifications_created ON notifications(created_at)");

                $this->pdo->exec("CREATE TABLE IF NOT EXISTS ai_agent_progress (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    task_id TEXT NOT NULL,
                    session_id TEXT NOT NULL,
                    user_id INTEGER NOT NULL,
                    status TEXT DEFAULT 'queued',
                    current_tool TEXT DEFAULT '',
                    iteration INTEGER DEFAULT 0,
                    progress_percent INTEGER DEFAULT 0,
                    result_summary TEXT DEFAULT '',
                    error_message TEXT DEFAULT '',
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL
                )");
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_ai_progress_task ON ai_agent_progress(task_id)");
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_ai_progress_user ON ai_agent_progress(user_id, status)");
            } elseif ($this->dbType === 'mysql') {
                $this->pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    type VARCHAR(50) NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    body TEXT,
                    related_id VARCHAR(64) DEFAULT '',
                    is_read TINYINT DEFAULT 0,
                    created_at INT NOT NULL,
                    INDEX idx_notifications_user (user_id, is_read, created_at),
                    INDEX idx_notifications_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                $this->pdo->exec("CREATE TABLE IF NOT EXISTS ai_agent_progress (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    task_id VARCHAR(64) NOT NULL,
                    session_id VARCHAR(64) NOT NULL,
                    user_id INT NOT NULL,
                    status VARCHAR(20) DEFAULT 'queued',
                    current_tool VARCHAR(64) DEFAULT '',
                    iteration INT DEFAULT 0,
                    progress_percent INT DEFAULT 0,
                    result_summary TEXT,
                    error_message TEXT,
                    created_at INT NOT NULL,
                    updated_at INT NOT NULL,
                    INDEX idx_ai_progress_task (task_id),
                    INDEX idx_ai_progress_user (user_id, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } elseif ($this->dbType === 'pgsql') {
                $this->pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
                    id SERIAL PRIMARY KEY,
                    user_id INTEGER NOT NULL,
                    type VARCHAR(50) NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    body TEXT DEFAULT '',
                    related_id VARCHAR(64) DEFAULT '',
                    is_read INTEGER DEFAULT 0,
                    created_at INTEGER NOT NULL
                )");
                $this->createIndex('idx_notifications_user', 'notifications', 'user_id, is_read, created_at');
                $this->createIndex('idx_notifications_created', 'notifications', 'created_at');

                $this->pdo->exec("CREATE TABLE IF NOT EXISTS ai_agent_progress (
                    id SERIAL PRIMARY KEY,
                    task_id VARCHAR(64) NOT NULL,
                    session_id VARCHAR(64) NOT NULL,
                    user_id INTEGER NOT NULL,
                    status VARCHAR(20) DEFAULT 'queued',
                    current_tool VARCHAR(64) DEFAULT '',
                    iteration INTEGER DEFAULT 0,
                    progress_percent INTEGER DEFAULT 0,
                    result_summary TEXT DEFAULT '',
                    error_message TEXT DEFAULT '',
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL
                )");
                $this->createIndex('idx_ai_progress_task', 'ai_agent_progress', 'task_id');
                $this->createIndex('idx_ai_progress_user', 'ai_agent_progress', 'user_id, status');
            }
        } catch (PDOException $e) {
            error_log("[SchemaManager] migrate_v6: " . $e->getMessage());
        }
    }

    /**
     * v7: AI Agent TODO 系统表
     */
    private function migrate_v7(): void
    {
        try {
            if ($this->dbType === 'sqlite') {
                $this->pdo->exec("CREATE TABLE IF NOT EXISTS ai_agent_todos (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    session_id TEXT NOT NULL,
                    user_id INTEGER NOT NULL,
                    content TEXT NOT NULL,
                    status TEXT DEFAULT 'pending',
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL,
                    order_idx INTEGER DEFAULT 0
                )");
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_ai_todos_session ON ai_agent_todos(session_id, order_idx)");
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_ai_todos_user ON ai_agent_todos(user_id)");
            } elseif ($this->dbType === 'mysql') {
                $this->pdo->exec("CREATE TABLE IF NOT EXISTS ai_agent_todos (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    session_id VARCHAR(64) NOT NULL,
                    user_id INT NOT NULL,
                    content TEXT NOT NULL,
                    status VARCHAR(20) DEFAULT 'pending',
                    created_at INT NOT NULL,
                    updated_at INT NOT NULL,
                    order_idx INT DEFAULT 0,
                    INDEX idx_ai_todos_session (session_id, order_idx),
                    INDEX idx_ai_todos_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } elseif ($this->dbType === 'pgsql') {
                $this->pdo->exec("CREATE TABLE IF NOT EXISTS ai_agent_todos (
                    id SERIAL PRIMARY KEY,
                    session_id VARCHAR(64) NOT NULL,
                    user_id INTEGER NOT NULL,
                    content TEXT NOT NULL,
                    status VARCHAR(20) DEFAULT 'pending',
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL,
                    order_idx INTEGER DEFAULT 0
                )");
                $this->createIndex('idx_ai_todos_session', 'ai_agent_todos', 'session_id, order_idx');
                $this->createIndex('idx_ai_todos_user', 'ai_agent_todos', 'user_id');
            }
        } catch (PDOException $e) {
            error_log("[SchemaManager] migrate_v7: " . $e->getMessage());
        }
    }

    private function migrate_v8(): void
    {
        try {
            // ai_agent_progress 增加 cancel_requested 字段，用于前端取消正在执行的任务
            if ($this->dbType === 'sqlite') {
                $this->addColumnSQLite('ai_agent_progress', 'cancel_requested', "INTEGER DEFAULT 0");
            } elseif ($this->dbType === 'mysql') {
                $this->addColumnMySQL('ai_agent_progress', 'cancel_requested', "TINYINT DEFAULT 0");
            } elseif ($this->dbType === 'pgsql') {
                $this->addColumnPgSQL('ai_agent_progress', 'cancel_requested', "SMALLINT DEFAULT 0");
            }
        } catch (PDOException $e) {
            error_log("[SchemaManager] migrate_v8: " . $e->getMessage());
        }
    }

    /**
     * 创建异步任务队列表（AsyncTaskQueue 使用）。
     */
    private function createAsyncTasksTable(): void
    {
        try {
            if ($this->dbType === 'sqlite') {
                $this->pdo->exec("CREATE TABLE IF NOT EXISTS async_tasks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    type TEXT NOT NULL,
                    payload TEXT NOT NULL,
                    status TEXT DEFAULT 'pending',
                    priority INTEGER DEFAULT 5,
                    retries INTEGER DEFAULT 0,
                    created_at INTEGER NOT NULL,
                    processing_since INTEGER,
                    error TEXT DEFAULT ''
                )");
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_async_tasks_status_created ON async_tasks(status, created_at)");
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_async_tasks_processing_since ON async_tasks(processing_since)");
            } elseif ($this->dbType === 'mysql') {
                $this->pdo->exec("CREATE TABLE IF NOT EXISTS async_tasks (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    type VARCHAR(64) NOT NULL,
                    payload TEXT NOT NULL,
                    status VARCHAR(20) DEFAULT 'pending',
                    priority INT DEFAULT 5,
                    retries INT DEFAULT 0,
                    created_at INT NOT NULL,
                    processing_since INT DEFAULT NULL,
                    error TEXT,
                    INDEX idx_async_tasks_status_created (status, created_at),
                    INDEX idx_async_tasks_processing_since (processing_since)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } elseif ($this->dbType === 'pgsql') {
                $this->pdo->exec("CREATE TABLE IF NOT EXISTS async_tasks (
                    id SERIAL PRIMARY KEY,
                    type VARCHAR(64) NOT NULL,
                    payload TEXT NOT NULL,
                    status VARCHAR(20) DEFAULT 'pending',
                    priority INTEGER DEFAULT 5,
                    retries INTEGER DEFAULT 0,
                    created_at INTEGER NOT NULL,
                    processing_since INTEGER,
                    error TEXT DEFAULT ''
                )");
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_async_tasks_status_created ON async_tasks(status, created_at)");
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_async_tasks_processing_since ON async_tasks(processing_since)");
            }
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
    }

    /**
     * v2 索引优化：
     * 1. 删除 idx_files_user_parent（与唯一索引 idx_files_user_parent_filename 前缀重复）
     * 2. share_visits 添加 (share_id, visit_type, created_at) 复合索引
     * 3. operation_logs 删除 idx_logs_category、idx_logs_severity、idx_logs_user，
     *    添加 (user_id, created_at) 复合索引（保留 idx_logs_created 用于纯时间排序）
     */
    private function optimizeIndexesV2(): void
    {
        // 1. 删除冗余索引 idx_files_user_parent（MySQL 中索引名为 idx_user_parent）
        try {
            if ($this->dbType === 'mysql') {
                $this->pdo->exec("DROP INDEX idx_user_parent ON files");
            } else {
                $this->pdo->exec("DROP INDEX IF EXISTS idx_files_user_parent");
            }
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }

        // 2. share_visits 添加复合索引
        try {
            if ($this->dbType === 'mysql') {
                $stmt = $this->pdo->query("SHOW INDEX FROM share_visits WHERE Key_name = 'idx_share_visits_share_type_created'");
                if ($stmt === false || $stmt->rowCount() === 0) {
                    $this->pdo->exec("CREATE INDEX idx_share_visits_share_type_created ON share_visits(share_id, visit_type, created_at)");
                }
            } else {
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_share_visits_share_type_created ON share_visits(share_id, visit_type, created_at)");
            }
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }

        // 3. operation_logs 索引优化：删除低频索引
        try {
            if ($this->dbType === 'mysql') {
                $this->pdo->exec("DROP INDEX idx_logs_category ON operation_logs");
            } else {
                $this->pdo->exec("DROP INDEX IF EXISTS idx_logs_category");
            }
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }

        try {
            if ($this->dbType === 'mysql') {
                $this->pdo->exec("DROP INDEX idx_logs_severity ON operation_logs");
            } else {
                $this->pdo->exec("DROP INDEX IF EXISTS idx_logs_severity");
            }
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }

        // 删除 idx_logs_user（被复合索引 idx_logs_user_created 覆盖）
        try {
            if ($this->dbType === 'mysql') {
                $this->pdo->exec("DROP INDEX idx_logs_user ON operation_logs");
            } else {
                $this->pdo->exec("DROP INDEX IF EXISTS idx_logs_user");
            }
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }

        // 添加复合索引 idx_logs_user_created
        try {
            if ($this->dbType === 'mysql') {
                $stmt = $this->pdo->query("SHOW INDEX FROM operation_logs WHERE Key_name = 'idx_logs_user_created'");
                if ($stmt === false || $stmt->rowCount() === 0) {
                    $this->pdo->exec("CREATE INDEX idx_logs_user_created ON operation_logs(user_id, created_at)");
                }
            } else {
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_logs_user_created ON operation_logs(user_id, created_at)");
            }
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
    }

    /**
     * 移除废弃的 users.storage_used 列。
     *
     * - SQLite 3.35+ 支持 ALTER TABLE DROP COLUMN，旧版本保留列（视为废弃）
     * - MySQL / PostgreSQL 直接 DROP COLUMN
     */
    private function dropStorageUsedColumn(): void
    {
        try {
            if ($this->dbType === 'sqlite') {
                $stmt = $this->pdo->query("PRAGMA table_info(users)");
                if ($stmt === false) {
                    return;
                }
                $exists = false;
                foreach ($stmt->fetchAll() as $col) {
                    if ($col['name'] === 'storage_used') {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    return;
                }
                // SQLite 3.35+ 支持 DROP COLUMN
                $version = $this->pdo->query('SELECT sqlite_version()')->fetchColumn();
                if (version_compare($version, '3.35', '>=')) {
                    $this->pdo->exec("ALTER TABLE users DROP COLUMN storage_used");
                }
                // 版本不支持时保留列，视为废弃（User model 已移除相关方法）
            } elseif ($this->dbType === 'mysql') {
                $stmt = $this->pdo->query("SHOW COLUMNS FROM users LIKE 'storage_used'");
                if ($stmt !== false && $stmt->rowCount() > 0) {
                    $this->pdo->exec("ALTER TABLE users DROP COLUMN storage_used");
                }
            } elseif ($this->dbType === 'pgsql') {
                $stmt = $this->pdo->prepare(
                    "SELECT 1 FROM information_schema.columns
                     WHERE table_name = 'users' AND column_name = 'storage_used' AND table_schema = 'public'"
                );
                $stmt->execute();
                if ($stmt->rowCount() > 0) {
                    $this->pdo->exec("ALTER TABLE users DROP COLUMN storage_used");
                }
            }
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
    }

    // ========================================================================
    //  SQLite
    // ========================================================================

    private function initTablesSQLite(): void
    {
        // page_size �?auto_vacuum 只能在空库设置，放在任何建表之前
        // 如果数据库已存在表，SQLite 静默忽略这些 PRAGMA
        try {
            $this->pdo->exec('PRAGMA page_size=4096');
            $this->pdo->exec('PRAGMA auto_vacuum=INCREMENTAL');
        } catch (\PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            email TEXT DEFAULT '',
            avatar TEXT DEFAULT '',
            role TEXT DEFAULT 'user',
            storage_limit INTEGER DEFAULT 10737418240,
            encryption_key TEXT DEFAULT '',
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            last_login INTEGER DEFAULT 0
        )");

        $this->addColumnSQLite('users', 'role', "TEXT DEFAULT 'user'");
        $this->addColumnSQLite('users', 'encryption_key', "TEXT DEFAULT ''");
        $this->addColumnSQLite('operation_logs', 'category', "TEXT DEFAULT ''");
        $this->addColumnSQLite('operation_logs', 'severity', "TEXT DEFAULT 'info'");
        $this->addColumnSQLite('operation_logs', 'user_agent', "TEXT DEFAULT ''");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            filename TEXT NOT NULL,
            filepath TEXT NOT NULL,
            filesize INTEGER DEFAULT 0,
            file_type TEXT DEFAULT '',
            mime_type TEXT DEFAULT '',
            is_dir INTEGER DEFAULT 0,
            parent_id INTEGER DEFAULT 0,
            path_hash TEXT NOT NULL,
            description TEXT DEFAULT '',
            is_favorite INTEGER DEFAULT 0,
            is_locked INTEGER DEFAULT 0,
            is_encrypted INTEGER DEFAULT 0,
            sort_order INTEGER DEFAULT 0,
            tags TEXT DEFAULT '',
            content_hash TEXT DEFAULT '',
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        $this->addColumnSQLite('files', 'tags', "TEXT DEFAULT ''");
        $this->addColumnSQLite('files', 'is_locked', "INTEGER DEFAULT 0");
        $this->addColumnSQLite('files', 'sort_order', "INTEGER DEFAULT 0");
        $this->addColumnSQLite('files', 'is_encrypted', "INTEGER DEFAULT 0");
        $this->addColumnSQLite('files', 'content_hash', "TEXT DEFAULT ''");

        // 预防并发上传竞态的数据库级唯一约束
        $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_files_user_parent_filename ON files(user_id, parent_id, filename)");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS trash (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            file_id INTEGER NOT NULL,
            filename TEXT NOT NULL,
            filepath TEXT NOT NULL,
            filesize INTEGER DEFAULT 0,
            file_type TEXT DEFAULT '',
            mime_type TEXT DEFAULT '',
            is_dir INTEGER DEFAULT 0,
            parent_id INTEGER DEFAULT 0,
            original_path TEXT DEFAULT '',
            deleted_at INTEGER NOT NULL,
            expire_at INTEGER NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS shares (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            file_id INTEGER NOT NULL,
            share_token TEXT NOT NULL UNIQUE,
            share_password TEXT DEFAULT '',
            download_count INTEGER DEFAULT 0,
            max_downloads INTEGER DEFAULT 0,
            expire_at INTEGER DEFAULT 0,
            created_at INTEGER NOT NULL,
            is_active INTEGER DEFAULT 1,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS share_visits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            share_id INTEGER NOT NULL,
            ip TEXT DEFAULT '',
            user_agent TEXT DEFAULT '',
            referer TEXT DEFAULT '',
            visit_type TEXT DEFAULT 'view',
            country TEXT DEFAULT '',
            city TEXT DEFAULT '',
            created_at INTEGER NOT NULL,
            FOREIGN KEY (share_id) REFERENCES shares(id) ON DELETE CASCADE
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS upload_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            upload_id TEXT NOT NULL,
            filename TEXT NOT NULL,
            total_size INTEGER DEFAULT 0,
            total_chunks INTEGER DEFAULT 0,
            uploaded_chunks TEXT DEFAULT '[]',
            file_md5 TEXT DEFAULT '',
            parent_id INTEGER DEFAULT 0,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        $this->addColumnSQLite('upload_tasks', 'file_md5', "TEXT DEFAULT ''");
        $this->addColumnSQLite('upload_tasks', 'parent_id', "INTEGER DEFAULT 0");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS operation_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            category TEXT DEFAULT '',
            severity TEXT DEFAULT 'info',
            target TEXT DEFAULT '',
            detail TEXT DEFAULT '',
            ip TEXT DEFAULT '',
            user_agent TEXT DEFAULT '',
            created_at INTEGER NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS recent_access (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            file_id INTEGER NOT NULL,
            filename TEXT NOT NULL DEFAULT '',
            filepath TEXT NOT NULL DEFAULT '',
            filesize INTEGER DEFAULT 0,
            file_type TEXT DEFAULT '',
            is_dir INTEGER DEFAULT 0,
            accessed_at INTEGER NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        $this->createIndex('idx_files_path_hash', 'files', 'path_hash');
        $this->createIndex('idx_files_is_favorite', 'files', 'is_favorite');
        $this->createIndex('idx_files_content_hash', 'files', 'content_hash');
        // idx_shares_token 已由 share_token UNIQUE 约束自动创建，此处不再重复建索引
        $this->createIndex('idx_shares_active', 'shares', 'is_active, expire_at');
        $this->createIndex('idx_trash_user', 'trash', 'user_id');
        $this->createIndex('idx_trash_expire', 'trash', 'expire_at');
        $this->createIndex('idx_trash_user_path', 'trash', 'user_id, original_path');
        $this->createIndex('idx_upload_tasks_uid', 'upload_tasks', 'upload_id');
        $this->createIndex('idx_logs_created', 'operation_logs', 'created_at');
        $this->createIndex('idx_logs_user_created', 'operation_logs', 'user_id, created_at');
        $this->createIndex('idx_recent_user', 'recent_access', 'user_id, accessed_at');
        $this->createIndex('idx_share_visits_share', 'share_visits', 'share_id');
        $this->createIndex('idx_share_visits_created', 'share_visits', 'created_at');
        $this->createIndex('idx_share_visits_share_type_created', 'share_visits', 'share_id, visit_type, created_at');

        $this->initAITablesSQLite();
        $this->initFTS5Search();
    }

    private function initAITablesSQLite(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS ai_sessions (
            id TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL,
            title TEXT DEFAULT '新对话',
            context JSON,
            status TEXT DEFAULT 'active',
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS ai_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id TEXT NOT NULL,
            role TEXT NOT NULL,
            content TEXT DEFAULT '',
            tool_calls TEXT DEFAULT '',
            tool_call_id TEXT DEFAULT '',
            metadata TEXT DEFAULT '',
            created_at INTEGER NOT NULL,
            FOREIGN KEY (session_id) REFERENCES ai_sessions(id) ON DELETE CASCADE
        )");

        $this->createIndex('idx_ai_sessions_user', 'ai_sessions', 'user_id');
        $this->createIndex('idx_ai_sessions_updated', 'ai_sessions', 'updated_at');
        $this->createIndex('idx_ai_messages_session', 'ai_messages', 'session_id');
    }

    // ========================================================================
    //  MySQL
    // ========================================================================

    private function initTablesMySQL(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT '',
            avatar VARCHAR(500) DEFAULT '',
            role VARCHAR(50) DEFAULT 'user',
            storage_limit BIGINT DEFAULT 10737418240,
            encryption_key TEXT,
            created_at INT NOT NULL,
            updated_at INT NOT NULL,
            last_login INT DEFAULT 0,
            INDEX idx_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addColumnMySQL('users', 'role', "VARCHAR(50) DEFAULT 'user'");
        $this->addColumnMySQL('users', 'encryption_key', "TEXT");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS files (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            filename VARCHAR(500) NOT NULL,
            filepath VARCHAR(1000) NOT NULL,
            filesize BIGINT DEFAULT 0,
            file_type VARCHAR(50) DEFAULT '',
            mime_type VARCHAR(200) DEFAULT '',
            is_dir TINYINT DEFAULT 0,
            parent_id INT DEFAULT 0,
            path_hash VARCHAR(64) NOT NULL,
            description TEXT,
            is_favorite TINYINT DEFAULT 0,
            is_locked TINYINT DEFAULT 0,
            is_encrypted TINYINT DEFAULT 0,
            sort_order INT DEFAULT 0,
            tags TEXT,
            content_hash VARCHAR(64) DEFAULT '',
            created_at INT NOT NULL,
            updated_at INT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_path_hash (path_hash),
            INDEX idx_is_favorite (is_favorite),
            INDEX idx_content_hash (content_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addColumnMySQL('files', 'tags', "TEXT");
        $this->addColumnMySQL('files', 'is_locked', "TINYINT DEFAULT 0");
        $this->addColumnMySQL('files', 'sort_order', "INT DEFAULT 0");
        $this->addColumnMySQL('files', 'is_encrypted', "TINYINT DEFAULT 0");
        $this->addColumnMySQL('files', 'content_hash', "VARCHAR(64) DEFAULT ''");
        $this->createIndexMySQL('idx_files_user_parent_filename', 'files', 'user_id, parent_id, filename');

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS trash (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            file_id INT NOT NULL,
            filename VARCHAR(500) NOT NULL,
            filepath VARCHAR(1000) NOT NULL,
            filesize BIGINT DEFAULT 0,
            file_type VARCHAR(50) DEFAULT '',
            mime_type VARCHAR(200) DEFAULT '',
            is_dir TINYINT DEFAULT 0,
            parent_id INT DEFAULT 0,
            original_path TEXT,
            deleted_at INT NOT NULL,
            expire_at INT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_trash_user (user_id),
            INDEX idx_trash_expire (expire_at),
            INDEX idx_trash_user_path (user_id, original_path(255))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS shares (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            file_id INT NOT NULL,
            share_token VARCHAR(64) NOT NULL UNIQUE,
            share_password VARCHAR(255) DEFAULT '',
            download_count INT DEFAULT 0,
            max_downloads INT DEFAULT 0,
            expire_at INT DEFAULT 0,
            created_at INT NOT NULL,
            is_active TINYINT DEFAULT 1,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_shares_active (is_active, expire_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS share_visits (
            id INT PRIMARY KEY AUTO_INCREMENT,
            share_id INT NOT NULL,
            ip VARCHAR(50) DEFAULT '',
            user_agent TEXT,
            referer TEXT,
            visit_type VARCHAR(50) DEFAULT 'view',
            country VARCHAR(50) DEFAULT '',
            city VARCHAR(100) DEFAULT '',
            created_at INT NOT NULL,
            FOREIGN KEY (share_id) REFERENCES shares(id) ON DELETE CASCADE,
            INDEX idx_share_visits_share (share_id),
            INDEX idx_share_visits_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS upload_tasks (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            upload_id VARCHAR(64) NOT NULL,
            filename VARCHAR(500) NOT NULL,
            total_size BIGINT DEFAULT 0,
            total_chunks INT DEFAULT 0,
            uploaded_chunks TEXT,
            file_md5 VARCHAR(64) DEFAULT '',
            parent_id INT DEFAULT 0,
            created_at INT NOT NULL,
            updated_at INT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_upload_tasks_uid (upload_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addColumnMySQL('upload_tasks', 'file_md5', "VARCHAR(64) DEFAULT ''");
        $this->addColumnMySQL('upload_tasks', 'parent_id', "INT DEFAULT 0");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS operation_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            category VARCHAR(50) DEFAULT '',
            severity VARCHAR(20) DEFAULT 'info',
            target TEXT,
            detail TEXT,
            ip VARCHAR(50) DEFAULT '',
            user_agent TEXT,
            created_at INT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_logs_created (created_at),
            INDEX idx_logs_user_created (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS recent_access (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            file_id INT NOT NULL,
            filename VARCHAR(500) NOT NULL DEFAULT '',
            filepath VARCHAR(1000) NOT NULL DEFAULT '',
            filesize BIGINT DEFAULT 0,
            file_type VARCHAR(50) DEFAULT '',
            is_dir TINYINT DEFAULT 0,
            accessed_at INT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_recent_user (user_id, accessed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->initAITablesMySQL();
        $this->initFullTextSearchMySQL();
    }

    private function initAITablesMySQL(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS ai_sessions (
            id VARCHAR(64) PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(255) DEFAULT '新对话',
            context JSON,
            status VARCHAR(20) DEFAULT 'active',
            created_at INT NOT NULL,
            updated_at INT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_ai_sessions_user (user_id),
            INDEX idx_ai_sessions_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS ai_messages (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            session_id VARCHAR(64) NOT NULL,
            role VARCHAR(20) NOT NULL,
            content TEXT,
            tool_calls TEXT,
            tool_call_id VARCHAR(64) DEFAULT '',
            metadata TEXT,
            created_at INT NOT NULL,
            FOREIGN KEY (session_id) REFERENCES ai_sessions(id) ON DELETE CASCADE,
            INDEX idx_ai_messages_session (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // ========================================================================
    //  PostgreSQL
    // ========================================================================

    private function initTablesPgSQL(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            username VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT '',
            avatar VARCHAR(500) DEFAULT '',
            role VARCHAR(50) DEFAULT 'user',
            storage_limit BIGINT DEFAULT 10737418240,
            encryption_key TEXT DEFAULT '',
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            last_login INTEGER DEFAULT 0
        )");

        $this->addColumnPgSQL('users', 'role', "VARCHAR(50) DEFAULT 'user'");
        $this->addColumnPgSQL('users', 'encryption_key', "TEXT DEFAULT ''");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS files (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            filename VARCHAR(500) NOT NULL,
            filepath VARCHAR(1000) NOT NULL,
            filesize BIGINT DEFAULT 0,
            file_type VARCHAR(50) DEFAULT '',
            mime_type VARCHAR(200) DEFAULT '',
            is_dir INTEGER DEFAULT 0,
            parent_id INTEGER DEFAULT 0,
            path_hash VARCHAR(64) NOT NULL,
            description TEXT DEFAULT '',
            is_favorite INTEGER DEFAULT 0,
            is_locked INTEGER DEFAULT 0,
            is_encrypted INTEGER DEFAULT 0,
            sort_order INTEGER DEFAULT 0,
            tags TEXT DEFAULT '',
            content_hash VARCHAR(64) DEFAULT '',
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )");

        $this->addColumnPgSQL('files', 'tags', "TEXT DEFAULT ''");
        $this->addColumnPgSQL('files', 'is_locked', "INTEGER DEFAULT 0");
        $this->addColumnPgSQL('files', 'sort_order', "INTEGER DEFAULT 0");
        $this->addColumnPgSQL('files', 'is_encrypted', "INTEGER DEFAULT 0");
        $this->addColumnPgSQL('files', 'content_hash', "VARCHAR(64) DEFAULT ''");
        $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_files_user_parent_filename ON files(user_id, parent_id, filename)");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS trash (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            file_id INTEGER NOT NULL,
            filename VARCHAR(500) NOT NULL,
            filepath VARCHAR(1000) NOT NULL,
            filesize BIGINT DEFAULT 0,
            file_type VARCHAR(50) DEFAULT '',
            mime_type VARCHAR(200) DEFAULT '',
            is_dir INTEGER DEFAULT 0,
            parent_id INTEGER DEFAULT 0,
            original_path TEXT DEFAULT '',
            deleted_at INTEGER NOT NULL,
            expire_at INTEGER NOT NULL
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS shares (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            file_id INTEGER NOT NULL REFERENCES files(id) ON DELETE CASCADE,
            share_token VARCHAR(64) NOT NULL UNIQUE,
            share_password VARCHAR(255) DEFAULT '',
            download_count INTEGER DEFAULT 0,
            max_downloads INTEGER DEFAULT 0,
            expire_at INTEGER DEFAULT 0,
            created_at INTEGER NOT NULL,
            is_active INTEGER DEFAULT 1
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS share_visits (
            id SERIAL PRIMARY KEY,
            share_id INTEGER NOT NULL REFERENCES shares(id) ON DELETE CASCADE,
            ip VARCHAR(50) DEFAULT '',
            user_agent TEXT DEFAULT '',
            referer TEXT DEFAULT '',
            visit_type VARCHAR(50) DEFAULT 'view',
            country VARCHAR(50) DEFAULT '',
            city VARCHAR(100) DEFAULT '',
            created_at INTEGER NOT NULL
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS upload_tasks (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            upload_id VARCHAR(64) NOT NULL,
            filename VARCHAR(500) NOT NULL,
            total_size BIGINT DEFAULT 0,
            total_chunks INTEGER DEFAULT 0,
            uploaded_chunks JSONB DEFAULT '[]',
            file_md5 VARCHAR(64) DEFAULT '',
            parent_id INTEGER DEFAULT 0,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )");

        $this->addColumnPgSQL('upload_tasks', 'file_md5', "VARCHAR(64) DEFAULT ''");
        $this->addColumnPgSQL('upload_tasks', 'parent_id', "INTEGER DEFAULT 0");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS operation_logs (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            action VARCHAR(50) NOT NULL,
            category VARCHAR(50) DEFAULT '',
            severity VARCHAR(20) DEFAULT 'info',
            target TEXT DEFAULT '',
            detail TEXT DEFAULT '',
            ip VARCHAR(50) DEFAULT '',
            user_agent TEXT DEFAULT '',
            created_at INTEGER NOT NULL
        )");

        $this->addColumnPgSQL('operation_logs', 'category', "VARCHAR(50) DEFAULT ''");
        $this->addColumnPgSQL('operation_logs', 'severity', "VARCHAR(20) DEFAULT 'info'");
        $this->addColumnPgSQL('operation_logs', 'user_agent', "TEXT DEFAULT ''");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS recent_access (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            file_id INTEGER NOT NULL,
            filename VARCHAR(500) NOT NULL DEFAULT '',
            filepath VARCHAR(1000) NOT NULL DEFAULT '',
            filesize BIGINT DEFAULT 0,
            file_type VARCHAR(50) DEFAULT '',
            is_dir INTEGER DEFAULT 0,
            accessed_at INTEGER NOT NULL
        )");

        $this->createIndex('idx_files_path_hash', 'files', 'path_hash');
        $this->createIndex('idx_files_is_favorite', 'files', 'is_favorite');
        $this->createIndex('idx_files_content_hash', 'files', 'content_hash');
        // idx_shares_token 已由 share_token UNIQUE 约束自动创建，此处不再重复建索引
        $this->createIndex('idx_shares_active', 'shares', 'is_active, expire_at');
        $this->createIndex('idx_trash_user', 'trash', 'user_id');
        $this->createIndex('idx_trash_expire', 'trash', 'expire_at');
        $this->createIndex('idx_trash_user_path', 'trash', 'user_id, original_path');
        $this->createIndex('idx_upload_tasks_uid', 'upload_tasks', 'upload_id');
        $this->createIndex('idx_logs_created', 'operation_logs', 'created_at');
        $this->createIndex('idx_logs_user_created', 'operation_logs', 'user_id, created_at');
        $this->createIndex('idx_recent_user', 'recent_access', 'user_id, accessed_at');
        $this->createIndex('idx_share_visits_share', 'share_visits', 'share_id');
        $this->createIndex('idx_share_visits_created', 'share_visits', 'created_at');
        $this->createIndex('idx_share_visits_share_type_created', 'share_visits', 'share_id, visit_type, created_at');

        $this->initAITablesPgSQL();
        $this->initFullTextSearchPgSQL();
    }

    private function initAITablesPgSQL(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS ai_sessions (
            id VARCHAR(64) PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            title VARCHAR(255) DEFAULT '新对话',
            context JSONB,
            status VARCHAR(20) DEFAULT 'active',
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS ai_messages (
            id SERIAL PRIMARY KEY,
            session_id VARCHAR(64) NOT NULL REFERENCES ai_sessions(id) ON DELETE CASCADE,
            role VARCHAR(20) NOT NULL,
            content TEXT DEFAULT '',
            tool_calls TEXT DEFAULT '',
            tool_call_id VARCHAR(64) DEFAULT '',
            metadata TEXT DEFAULT '',
            created_at INTEGER NOT NULL
        )");

        $this->createIndex('idx_ai_sessions_user', 'ai_sessions', 'user_id');
        $this->createIndex('idx_ai_sessions_updated', 'ai_sessions', 'updated_at');
        $this->createIndex('idx_ai_messages_session', 'ai_messages', 'session_id');
    }

    // ========================================================================
    //  通用辅助
    // ========================================================================

    private function createIndex(string $name, string $table, string $columns): void
    {
        try {
            if ($this->dbType === 'sqlite') {
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$columns})");
            } elseif ($this->dbType === 'pgsql') {
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$columns})");
            } elseif ($this->dbType === 'mysql') {
                $stmt = $this->pdo->query("SHOW INDEX FROM {$table} WHERE Key_name = '{$name}'");
                if ($stmt === false || $stmt->rowCount() === 0) {
                    // MySQL 不允许对 TEXT/BLOB 列直接建索引，需指定前缀长度
                    $safeCols = preg_replace_callback('/\b(original_path|content)\b(?!\s*\()/', function ($m) {
                        return $m[1] . '(255)';
                    }, $columns);
                    $this->pdo->exec("CREATE INDEX {$name} ON {$table} ({$safeCols})");
                }
            }
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
    }

    private function createIndexMySQL(string $name, string $table, string $columns): void
    {
        try {
            $stmt = $this->pdo->query("SHOW INDEX FROM {$table} WHERE Key_name = '{$name}'");
            if ($stmt->rowCount() === 0) {
                $this->pdo->exec("CREATE UNIQUE INDEX {$name} ON {$table} ({$columns})");
            }
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
    }

    // ---------- 加列辅助 ----------

    private function addColumnSQLite(string $table, string $column, string $definition): void
    {
        try {
            $stmt = $this->pdo->query("PRAGMA table_info({$table})");
            foreach ($stmt->fetchAll() as $col) {
                if ($col['name'] === $column) {
                    return;
                }
            }
            $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
    }

    private function addColumnMySQL(string $table, string $column, string $definition): void
    {
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
            if ($stmt->rowCount() === 0) {
                $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
    }

    private function addColumnPgSQL(string $table, string $column, string $definition): void
    {
        try {
            $stmt = $this->pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ? AND table_schema = 'public'");
            $stmt->execute([$table, $column]);
            if ($stmt->rowCount() === 0) {
                $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
    }

    // ---------- 迁移辅助 ----------

    private function migrateExistingUsersToAdmin(): void
    {
        // 在 v2 迁移中一次性执行：将 role 为空/NULL 的用户提升为 admin
        try {
            $check = $this->pdo->query("SELECT COUNT(*) FROM users WHERE role = '' OR role IS NULL")->fetchColumn();
            if ($check > 0) {
                $sql = match ($this->dbType) {
                    'sqlite' => "UPDATE users SET role = 'admin' WHERE role = '' OR role IS NULL",
                    'mysql'  => "UPDATE users SET role = 'admin' WHERE role = '' OR role IS NULL",
                    'pgsql'  => "UPDATE users SET role = 'admin' WHERE role = '' OR role IS NULL",
                    default  => null,
                };
                if ($sql !== null) {
                    $this->pdo->exec($sql);
                }
            }
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
    }

    /**
     * 为 recent_access 添加 (user_id, file_id) 唯一索引。
     *
     * 这是 UPSERT 操作的前置条件：recordAccess 用 INSERT OR REPLACE
     * 替代 delete-insert，需要唯一约束来判断冲突。
     *
     * 安全迁移：先清理重复数据（保留最新 id），再创建唯一索引。
     * 清理失败或索引已存在时静默跳过。
     */
    private function addRecentAccessUniqueIndex(): void
    {
        try {
            // 清理重复数据：每组 (user_id, file_id) 只保留 id 最大的行
            if ($this->dbType === 'sqlite') {
                $this->pdo->exec("DELETE FROM recent_access WHERE id NOT IN (
                    SELECT MAX(id) FROM recent_access GROUP BY user_id, file_id
                )");
            } elseif ($this->dbType === 'mysql') {
                $this->pdo->exec("DELETE r1 FROM recent_access r1
                    INNER JOIN recent_access r2
                    WHERE r1.user_id = r2.user_id AND r1.file_id = r2.file_id
                    AND r1.id < r2.id");
            } elseif ($this->dbType === 'pgsql') {
                $this->pdo->exec("DELETE FROM recent_access a USING recent_access b
                    WHERE a.user_id = b.user_id AND a.file_id = b.file_id AND a.id < b.id");
            }

            // 创建唯一索引
            if ($this->dbType === 'sqlite' || $this->dbType === 'pgsql') {
                $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_recent_user_file ON recent_access (user_id, file_id)");
            } else {
                $this->createIndexMySQL('idx_recent_user_file', 'recent_access', 'user_id, file_id');
            }
        } catch (PDOException $e) {
            // 索引创建失败（可能有并发写入），忽略，下次启动重试
        }
    }

    // ---------- 全文搜索 ----------

    private function initFTS5Search(): void
    {
        try {
            $this->pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS files_fts USING fts5(
                filename,
                description,
                tags,
                content='files',
                content_rowid='id'
            )");

            $this->pdo->exec("CREATE TRIGGER IF NOT EXISTS files_ai AFTER INSERT ON files BEGIN
                INSERT INTO files_fts(rowid, filename, description, tags)
                VALUES (new.id, new.filename, new.description, new.tags);
            END");

            $this->pdo->exec("CREATE TRIGGER IF NOT EXISTS files_ad AFTER DELETE ON files BEGIN
                INSERT INTO files_fts(files_fts, rowid, filename, description, tags)
                VALUES('delete', old.id, old.filename, old.description, old.tags);
            END");

            $this->pdo->exec("CREATE TRIGGER IF NOT EXISTS files_au AFTER UPDATE ON files BEGIN
                INSERT INTO files_fts(files_fts, rowid, filename, description, tags)
                VALUES('delete', old.id, old.filename, old.description, old.tags);
                INSERT INTO files_fts(rowid, filename, description, tags)
                VALUES (new.id, new.filename, new.description, new.tags);
            END");

            $this->rebuildFTS5IfStale();
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
    }

    private function initFullTextSearchMySQL(): void
    {
        try {
            $this->pdo->exec("ALTER TABLE files ADD FULLTEXT INDEX idx_files_fulltext (filename, description, tags)");
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
    }

    private function initFullTextSearchPgSQL(): void
    {
        try {
            $this->pdo->exec("CREATE EXTENSION IF NOT EXISTS pg_trgm");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_files_trgm ON files USING gin (filename gin_trgm_ops)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_files_desc_trgm ON files USING gin (description gin_trgm_ops)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_files_tags_trgm ON files USING gin (tags gin_trgm_ops)");
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
    }

    /**
     * 重建 FTS5 索引——当 files 表行数与 files_fts 不一致时触发�?     */
    private function rebuildFTS5IfStale(): void
    {
        // 只在 5% 的请求中检查 FTS 一致性，减少 COUNT 查询频率
        if (random_int(1, 20) !== 1) {
            return;
        }

        // 用文件锁防止多 fpm worker 并发重建 FTS 索引
        ConcurrencyGuard::getInstance()->withFileLock('fts5_rebuild', function () {
            try {
                $count = $this->pdo->query("SELECT COUNT(*) FROM files")->fetchColumn();
                $ftsCount = $this->pdo->query("SELECT COUNT(*) FROM files_fts")->fetchColumn();

                if ($count !== false && $ftsCount !== false && $count != $ftsCount) {
                    $this->pdo->exec("INSERT INTO files_fts(files_fts) VALUES('rebuild')");
                }
            } catch (PDOException $e) {
                error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            }
        });
    }

    /**
     * 手动触发 FTS5 重建（可从外部调用）�?     */
    public function rebuildFTS5(): void
    {
        try {
            $this->pdo->exec("INSERT INTO files_fts(files_fts) VALUES('rebuild')");
        } catch (PDOException $e) {
            error_log("[SchemaManager] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
    }
}



