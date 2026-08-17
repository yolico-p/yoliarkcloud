<?php

namespace App\Models;

use App\Core\Model;
use App\Core\Security;
use App\Core\Config;

class User extends Model
{
    protected static string $table = 'users';
    protected static array $fillable = [
        'username', 'password_hash', 'email', 'avatar', 'role',
        'storage_limit', 'encryption_key',
        'created_at', 'updated_at', 'last_login',
    ];
    protected static array $guarded = ['id'];

    // ========================================================================
    //  业务方法
    // ========================================================================

    public static function findByUsername(string $username): ?self
    {
        return self::where('username = ?', [$username])->first();
    }

    public static function findByEmail(string $email): ?self
    {
        return self::where('email = ?', [$email])->first();
    }

    public function verifyPassword(string $password): bool
    {
        return Security::verifyPassword($password, $this->attributes['password_hash'] ?? '');
    }

    public function setPassword(string $password): static
    {
        $this->attributes['password_hash'] = Security::hashPassword($password);
        return $this;
    }

    public function isAdmin(): bool
    {
        return ($this->attributes['role'] ?? '') === 'admin';
    }

    public function hasRole(string $role): bool
    {
        return ($this->attributes['role'] ?? '') === $role;
    }

    public function getRemainingStorage(): int
    {
        // storage_used 列在 v2 迁移已删除，实时聚合查询由 AuthService 负责
        return max(0, ($this->attributes['storage_limit'] ?? 0));
    }

    public function hasStorageFor(int $bytes): bool
    {
        return true; // 实际限额检查由 AuthService::checkStorageLimit 处理
    }
}
