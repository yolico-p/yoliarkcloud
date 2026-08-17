<?php

namespace App\Core;

use PDO;

/**
 * Model 基类 — 轻量 Active Record。
 *
 * 提供基础的 CRUD 封装，降低 Service 层与裸 Database 的耦合。
 * 每个子类对应一张数据库表，通过 `$table` 属性声明。
 *
 * 用法：
 *   $user = User::find(1);
 *   $users = User::where('role = ?', ['admin'])->get();
 *   $user->fill(['email' => 'a@b.com'])->save();
 *   $user->delete();
 *
 * 注意：这不是完整的 ORM，不做关联、延迟加载、变更追踪。
 * 但足够把 "裸 SQL 写在 Service 里" 的情况减少 80%。
 */
abstract class Model
{
    /** 子类必须覆盖 → 表名 */
    protected static string $table = '';

    /** 主键列名 */
    protected static string $primaryKey = 'id';

    /** 是否使用自增主键（false 表示手动赋值） */
    protected static bool $autoIncrement = true;

    /** 属性白名单 — 空数组表示允许全部 */
    protected static array $fillable = [];

    /** 只读属性 — 写入时跳过 */
    protected static array $guarded = [];

    /** 是否启用自动时间戳（created_at / updated_at）。
     *  默认 false：现有子类均手动维护时间戳，保持 false 以维持现有行为；
     *  需要自动时间戳的子类可显式设为 true。 */
    public static bool $timestamps = false;

    /** 自动时间戳列名（可由子类覆盖） */
    protected array $timestampsColumns = ['created_at', 'updated_at'];

    /** 是否启用软删除 */
    public static bool $softDelete = false;

    /** 软删除标记列名 */
    protected string $deletedAtColumn = 'deleted_at';

    /** 当前实例的属性数据 */
    protected array $attributes = [];

    /** 原始属性数据（用于判断是否被修改） */
    protected array $original = [];

    /** 标记是否为新记录 */
    protected bool $exists = false;

    /**
     * 查询构建器暂存（实例属性）。
     * 注意：早期版本使用 static 属性，会导致多个 Model 子类链式调用互相污染，
     * 现改为实例属性，每个查询链路拥有独立状态。
     */
    private array $_query = [];

    // ========================================================================
    //  构造 / 填充
    // ========================================================================

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /**
     * 批量赋值。被 $fillable / $guarded 过滤。
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->attributes[$key] = $value;
            }
        }
        return $this;
    }

    /**
     * 将数据库行数据注入实例（跳过 fillable 过滤，标记为已持久化）。
     */
    public function forceFill(array $attributes): static
    {
        $this->attributes = array_merge($this->attributes, $attributes);
        $this->original = $this->attributes;
        $this->exists = true;
        return $this;
    }

    // ========================================================================
    //  查询构建
    // ========================================================================

    /**
     * 按主键查找。
     */
    public static function find(int|string $id): ?static
    {
        $table = static::getTable();
        $pk = static::$primaryKey;
        $sql = "SELECT * FROM {$table} WHERE {$pk} = ?";
        $params = [$id];

        // 软删除：自动排除已删除记录
        if (static::$softDelete) {
            $col = static::getDeletedAtColumn();
            $sql .= " AND {$col} IS NULL";
        }

        $row = static::db()->fetch($sql, $params);
        if (!$row) {
            return null;
        }
        return (new static())->forceFill($row);
    }

    /**
     * 按条件查找第一条（链式入口）。
     */
    public static function where(string $where, array $params = []): static
    {
        $instance = new static();
        $instance->_query = [
            'table'   => static::getTable(),
            'where'   => $where,
            'params'  => $params,
            'order'   => '',
            'limit'   => 0,
            'offset'  => 0,
            'trashed' => 'exclude', // 默认排除软删除记录（仅在 $softDelete=true 时生效）
        ];
        return $instance;
    }

    /**
     * 设置排序（链式）。
     *
     * 安全性：
     * - $direction 强制 ASC/DESC 白名单，避免 ORDER BY 注入
     * - $column 走标识符正则校验 + $fillable 白名单（若子类声明了 fillable）
     *
     * 若子类 $fillable 为空（表示允许全部），则只走标识符格式校验，
     * 调用方需保证 column 名来自硬编码常量而非用户输入。
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->ensureQuery();

        // 1. 方向白名单
        $dirUpper = strtoupper(trim($direction));
        if (!in_array($dirUpper, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException(
                "Model::orderBy direction must be 'ASC' or 'DESC', got '{$direction}'"
            );
        }

        // 2. 列名格式校验（挡住空格/分号/引号等注入字符）
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            throw new \InvalidArgumentException(
                "Model::orderBy column '{$column}' contains invalid characters"
            );
        }

        // 3. 列名白名单校验（若子类声明了 fillable）
        if (!empty(static::$fillable) && !in_array($column, static::$fillable, true)) {
            // 主键列默认允许（即使未在 fillable 中也允许排序）
            if ($column !== static::$primaryKey) {
                throw new \InvalidArgumentException(
                    "Model::orderBy column '{$column}' not in " . static::class . '::$fillable whitelist'
                );
            }
        }

        $this->_query['order'] = "{$column} {$dirUpper}";
        return $this;
    }

    /**
     * 设置偏移（链式）。
     */
    public function offset(int $offset): static
    {
        $this->ensureQuery();
        $this->_query['offset'] = $offset;
        return $this;
    }

    /**
     * 限制数量（链式）。
     */
    public function limit(int $limit): static
    {
        $this->ensureQuery();
        $this->_query['limit'] = $limit;
        return $this;
    }

    /**
     * 包含已软删除记录（链式）。
     */
    public function withTrashed(): static
    {
        $this->ensureQuery();
        $this->_query['trashed'] = 'with';
        return $this;
    }

    /**
     * 仅查询已软删除记录（链式）。
     */
    public function onlyTrashed(): static
    {
        $this->ensureQuery();
        $this->_query['trashed'] = 'only';
        return $this;
    }

    /**
     * 多用途 get 方法：
     * - 无参数时作为查询构建器终端（where()->get()），执行 SELECT 返回模型数组。
     * - 有参数时作为属性访问器（$model->get('key')），返回单个属性值。
     *
     * 合并原因：PHP 不支持基于签名的方法重载，两个同名 get() 会导致
     * "Cannot redeclare method" 致命错误。通过 func_num_args() 区分用途。
     */
    public function get(string $key = '', mixed $default = null): mixed
    {
        if (func_num_args() === 0) {
            // 查询构建器模式：执行 SELECT 返回模型数组
            $this->ensureQuery();
            $q = $this->_query;

            $sql = "SELECT * FROM {$q['table']} WHERE {$q['where']}" . $this->softDeleteSql();
            if ($q['order']) {
                $sql .= " ORDER BY {$q['order']}";
            }
            if ($q['limit'] > 0) {
                $sql .= " LIMIT {$q['limit']}";
            }
            if ($q['offset'] > 0) {
                $sql .= " OFFSET {$q['offset']}";
            }

            // 查询消费后重置，避免复用实例时残留
            $this->_query = [];

            $rows = static::db()->fetchAll($sql, $q['params']);
            return array_map(fn ($row) => (new static())->forceFill($row), $rows);
        }

        // 属性访问器模式：返回单个属性值
        return $this->attributes[$key] ?? $default;
    }

    /**
     * 获取第一条匹配结果。
     */
    public function first(): ?static
    {
        $this->ensureQuery();
        $q = $this->_query;

        $sql = "SELECT * FROM {$q['table']} WHERE {$q['where']}" . $this->softDeleteSql();
        if ($q['order']) {
            $sql .= " ORDER BY {$q['order']}";
        }
        $sql .= " LIMIT 1";

        $this->_query = [];

        $row = static::db()->fetch($sql, $q['params']);
        if (!$row) {
            return null;
        }
        return (new static())->forceFill($row);
    }

    /**
     * 根据任意条件判断记录是否存在。
     */
    public static function existsWhere(string $where, array $params = []): bool
    {
        $table = static::getTable();
        $sql = "SELECT 1 FROM {$table} WHERE {$where}";

        // 软删除：自动排除已删除记录
        if (static::$softDelete) {
            $col = static::getDeletedAtColumn();
            $sql .= " AND {$col} IS NULL";
        }

        return (bool) static::db()->fetch($sql . " LIMIT 1", $params);
    }

    /**
     * 统计条数。
     */
    public static function count(string $where = '1=1', array $params = []): int
    {
        $table = static::getTable();
        $sql = "SELECT COUNT(*) AS cnt FROM {$table} WHERE {$where}";

        // 软删除：自动排除已删除记录
        if (static::$softDelete) {
            $col = static::getDeletedAtColumn();
            $sql .= " AND {$col} IS NULL";
        }

        $row = static::db()->fetch($sql, $params);
        return (int) ($row['cnt'] ?? 0);
    }

    // ========================================================================
    //  持久化
    // ========================================================================

    /**
     * 保存（INSERT 或 UPDATE）。
     */
    public function save(): bool
    {
        if ($this->exists) {
            return $this->performUpdate();
        }
        return $this->performInsert();
    }

    /**
     * 删除当前记录。
     * - 软删除开启时执行 UPDATE deleted_at = time()
     * - 否则执行硬删除 DELETE
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }
        $table = static::getTable();
        $pk = static::$primaryKey;
        $id = $this->getKey();

        if (static::$softDelete) {
            $col = $this->deletedAtColumn;
            $now = time();
            static::db()->update($table, [$col => $now], "{$pk} = ?", [$id]);
            $this->attributes[$col] = $now;
            $this->original[$col] = $now;
        } else {
            static::db()->delete($table, "{$pk} = ?", [$id]);
            $this->exists = false;
        }

        static::db()->invalidateTableCache($table);
        return true;
    }

    /**
     * 强制硬删除（即使开启软删除也直接 DELETE）。
     */
    public function forceDelete(): bool
    {
        if (!$this->exists) {
            return false;
        }
        $table = static::getTable();
        $pk = static::$primaryKey;
        $id = $this->getKey();

        static::db()->delete($table, "{$pk} = ?", [$id]);
        $this->exists = false;
        static::db()->invalidateTableCache($table);
        return true;
    }

    /**
     * 恢复软删除记录。
     */
    public function restore(): bool
    {
        if (!static::$softDelete || !$this->exists) {
            return false;
        }
        $table = static::getTable();
        $pk = static::$primaryKey;
        $id = $this->getKey();
        $col = $this->deletedAtColumn;

        static::db()->update($table, [$col => null], "{$pk} = ?", [$id]);
        $this->attributes[$col] = null;
        $this->original[$col] = null;
        static::db()->invalidateTableCache($table);
        return true;
    }

    /**
     * 显式执行软删除（UPDATE SET deleted_at = time()）。
     *
     * 与 delete() 不同：无论 $softDelete 开关如何，本方法都执行软删除语义，
     * 适用于需要强制软删除的场景。仅在表具备 deleted_at 列时调用。
     */
    public function softDelete(): bool
    {
        if (!$this->exists) {
            return false;
        }
        $table = static::getTable();
        $pk = static::$primaryKey;
        $id = $this->getKey();
        $col = $this->deletedAtColumn;
        $now = time();

        static::db()->update($table, [$col => $now], "{$pk} = ?", [$id]);
        $this->attributes[$col] = $now;
        $this->original[$col] = $now;
        static::db()->invalidateTableCache($table);
        return true;
    }

    /**
     * 判断当前实例是否已被软删除。
     */
    public function trashed(): bool
    {
        if (!static::$softDelete) {
            return false;
        }
        return isset($this->attributes[$this->deletedAtColumn])
            && $this->attributes[$this->deletedAtColumn] !== null;
    }

    // ========================================================================
    //  批量操作
    // ========================================================================

    /**
     * 批量插入（单条多行 VALUES 语法）。
     *
     * @param array $records 待插入记录数组，每条为 [列 => 值]，所有记录应具有相同列
     * @return int 插入行数
     */
    public static function insertMany(array $records): int
    {
        if (empty($records)) {
            return 0;
        }

        $table = static::getTable();
        $columns = array_keys($records[0]);
        $colList = implode(', ', $columns);

        $rowPlaceholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $allPlaceholders = implode(', ', array_fill(0, count($records), $rowPlaceholders));

        $params = [];
        foreach ($records as $record) {
            foreach ($columns as $col) {
                $params[] = $record[$col] ?? null;
            }
        }

        $sql = "INSERT INTO {$table} ({$colList}) VALUES {$allPlaceholders}";
        static::db()->query($sql, $params);
        static::db()->invalidateTableCache($table);

        return count($records);
    }

    /**
     * 批量更新（CASE WHEN 语法）。
     *
     * @param string $column 用于 CASE 与 WHERE IN 的键列名（如 'id'）
     * @param array  $values [键值 => [列 => 新值, ...], ...]，每个键对应要更新的列
     * @param array  $data   额外的固定列值（所有行统一值），如 ['updated_at' => time()]
     * @return int 受影响行数
     */
    public static function updateMany(string $column, array $values, array $data): int
    {
        if (empty($values)) {
            return 0;
        }

        $table = static::getTable();
        $keys = array_keys($values);

        // 收集需要 CASE 更新的列名（取第一条记录的键）
        $caseColumns = array_keys($values[$keys[0]]);

        $setParts = [];
        $params = [];

        foreach ($caseColumns as $colName) {
            $caseParts = [];
            foreach ($values as $keyVal => $rowVals) {
                $caseParts[] = 'WHEN ? THEN ?';
                $params[] = $keyVal;
                $params[] = $rowVals[$colName] ?? null;
            }
            $setParts[] = "{$colName} = CASE {$column} " . implode(' ', $caseParts) . ' END';
        }

        // 固定值列
        foreach ($data as $col => $val) {
            $setParts[] = "{$col} = ?";
            $params[] = $val;
        }

        $setClause = implode(', ', $setParts);
        $inPlaceholders = implode(', ', array_fill(0, count($keys), '?'));

        $sql = "UPDATE {$table} SET {$setClause} WHERE {$column} IN ({$inPlaceholders})";
        foreach ($keys as $k) {
            $params[] = $k;
        }

        $affected = static::db()->query($sql, $params)->rowCount();
        static::db()->invalidateTableCache($table);

        return $affected;
    }

    // ========================================================================
    //  访问器
    // ========================================================================

    /**
     * 获取主键值。
     */
    public function getKey(): int|string|null
    {
        return $this->attributes[static::$primaryKey] ?? null;
    }

    /**
     * 获取所有属性。
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * 设置单个属性。
     */
    public function set(string $key, mixed $value): static
    {
        if ($this->isFillable($key)) {
            $this->attributes[$key] = $value;
        }
        return $this;
    }

    /**
     * 判断属性是否被修改过。
     */
    public function isDirty(?string $key = null): bool
    {
        if ($key === null) {
            return $this->attributes !== $this->original;
        }
        return ($this->attributes[$key] ?? null) !== ($this->original[$key] ?? null);
    }

    /**
     * 获取变更的属性。
     */
    public function getDirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (($this->original[$key] ?? null) !== $value) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    // ========================================================================
    //  内部
    // ========================================================================

    protected function performInsert(): bool
    {
        $table = static::getTable();

        // 自动时间戳（仅设置可填充的列，避免给无该列的表写入）
        $this->applyTimestamps(true);

        $data = $this->attributes;

        // 移除 guarded 属性
        foreach (static::$guarded as $g) {
            unset($data[$g]);
        }

        if (empty($data)) {
            return false;
        }

        $id = static::db()->insert($table, $data);
        if ($id && static::$autoIncrement) {
            $this->attributes[static::$primaryKey] = (int) $id;
        }
        $this->original = $this->attributes;
        $this->exists = true;
        return true;
    }

    protected function performUpdate(): bool
    {
        // 自动时间戳（更新 updated_at）
        $this->applyTimestamps(false);

        $dirty = $this->getDirty();
        if (empty($dirty)) {
            return true;
        }

        $table = static::getTable();
        $pk = static::$primaryKey;
        $id = $this->getKey();

        // 移除 guarded 属性
        foreach (static::$guarded as $g) {
            unset($dirty[$g]);
        }
        // 不允许修改主键
        unset($dirty[$pk]);

        if (empty($dirty)) {
            return true;
        }

        static::db()->update($table, $dirty, "{$pk} = ?", [$id]);
        $this->original = $this->attributes;
        return true;
    }

    /**
     * 应用自动时间戳。
     * - 插入：设置 created_at（若未设置且可填充）、updated_at（若可填充）
     * - 更新：设置 updated_at（若可填充）
     *
     * 通过 isFillable 守卫，避免向不具备该列的表（如仅含 created_at 的表）写入。
     */
    protected function applyTimestamps(bool $isInsert): void
    {
        if (!static::$timestamps) {
            return;
        }

        $cols = $this->timestampsColumns;
        $createdCol = $cols[0] ?? 'created_at';
        $updatedCol = $cols[1] ?? 'updated_at';
        $now = time();

        if ($isInsert) {
            if ($this->isFillable($createdCol) && !isset($this->attributes[$createdCol])) {
                $this->attributes[$createdCol] = $now;
            }
            if ($this->isFillable($updatedCol)) {
                $this->attributes[$updatedCol] = $now;
            }
        } else {
            if ($this->isFillable($updatedCol)) {
                $this->attributes[$updatedCol] = $now;
            }
        }
    }

    protected function isFillable(string $key): bool
    {
        if (in_array($key, static::$guarded, true)) {
            return false;
        }
        if (empty(static::$fillable)) {
            return true;
        }
        return in_array($key, static::$fillable, true);
    }

    /**
     * 确保实例已初始化查询构建器状态（供链式方法使用）。
     */
    private function ensureQuery(): void
    {
        if (empty($this->_query)) {
            $this->_query = [
                'table'   => static::getTable(),
                'where'   => '1=1',
                'params'  => [],
                'order'   => '',
                'limit'   => 0,
                'offset'  => 0,
                'trashed' => 'exclude',
            ];
        }
    }

    /**
     * 构造软删除 SQL 片段（带 AND 前缀，无则返回空串）。
     */
    private function softDeleteSql(): string
    {
        if (!static::$softDelete) {
            return '';
        }
        $col = $this->deletedAtColumn;
        $mode = $this->_query['trashed'] ?? 'exclude';
        if ($mode === 'with') {
            return '';
        }
        if ($mode === 'only') {
            return " AND {$col} IS NOT NULL";
        }
        return " AND {$col} IS NULL";
    }

    /**
     * 在静态上下文中获取软删除列名。
     */
    protected static function getDeletedAtColumn(): string
    {
        return (new static())->deletedAtColumn;
    }

    // ========================================================================
    //  静态工具
    // ========================================================================

    public static function getTable(): string
    {
        if (empty(static::$table)) {
            // 按类名自动推断表名
            $class = basename(str_replace('\\', '/', static::class));
            static::$table = strtolower(preg_replace('/(?<=[a-z])([A-Z])/', '_$1', $class)) . 's';
        }
        return static::$table;
    }

    protected static function db(): Database
    {
        return Database::getInstance();
    }

    /**
     * 开始链式调用的静态入口。
     * User::query()->where(...)->orderBy(...)->get()
     */
    public static function query(): static
    {
        $instance = new static();
        $instance->_query = [
            'table'   => static::getTable(),
            'where'   => '1=1',
            'params'  => [],
            'order'   => '',
            'limit'   => 0,
            'offset'  => 0,
            'trashed' => 'exclude',
        ];
        return $instance;
    }
}
