<?php

namespace App\Core;

/**
 * Container - 轻量服务容器（DI 入口）。
 *
 * 目的：为核心单例（Database / Config / ConcurrencyGuard / AdaptiveRateLimiter
 * 及各 Service）提供统一访问入口，降低调用方对具体 ::getInstance() 的硬依赖，
 * 便于未来替换实现或在测试中注入 mock。
 *
 * 设计取舍：
 * - 不强制现有代码立即迁移；::getInstance() 保留向后兼容。
 * - 工厂函数懒加载，首次 get() 才创建实例。
 * - singleton 注册的工厂只执行一次；set() 直接注册已有实例。
 * - 容器本身是单例（合理：全局服务注册中心）。
 *
 * 使用示例：
 *   Container::getInstance()->singleton('files', function ($c) {
 *       return new FileManagerService();
 *   });
 *   $files = Container::getInstance()->get('files');
 *   // 或经 helper：app('files')
 *
 * 迁移路径：新代码优先用 Container::get('xxx') 或 app('xxx')，
 * 旧代码逐步从 ::getInstance() 切换；最终目标是让 Service 构造函数
 * 接受依赖参数（如 __construct(Database $db, Config $config)），
 * 由 Container 完成依赖装配，彻底消除 Service 内部的 ::getInstance() 调用。
 */
class Container
{
    private static $instance = null;

    /** @var array<string, callable> 已注册的工厂闭包 */
    private $factories = [];

    /** @var array<string, object> 已解析的单例实例 */
    private $instances = [];

    /** @var array<string, bool> 标记为 singleton 的服务（工厂只执行一次） */
    private $singletons = [];

    private function __construct()
    {
        $this->registerDefaultServices();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    /**
     * 注册 singleton 服务：工厂只执行一次，结果缓存。
     */
    public function singleton(string $name, callable $factory): self
    {
        $this->factories[$name] = $factory;
        $this->singletons[$name] = true;
        return $this;
    }

    /**
     * 注册瞬时服务：每次 get() 都重新执行工厂。
     */
    public function bind(string $name, callable $factory): self
    {
        $this->factories[$name] = $factory;
        unset($this->singletons[$name]);
        unset($this->instances[$name]);
        return $this;
    }

    /**
     * 直接注册已有实例（跳过工厂）。
     */
    public function set(string $name, $instance): self
    {
        $this->instances[$name] = $instance;
        $this->singletons[$name] = true;
        unset($this->factories[$name]);
        return $this;
    }

    /**
     * 解析服务。singleton 已解析则返回缓存，否则执行工厂。
     *
     * @param array $parameters 仅对 bind 注册的瞬时服务有效，传给工厂
     */
    public function get(string $name, array $parameters = [])
    {
        // singleton 命中缓存
        if (isset($this->singletons[$name]) && isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        if (!isset($this->factories[$name])) {
            throw new \RuntimeException("Service [{$name}] not registered in container");
        }

        $instance = ($this->factories[$name])($this, $parameters);

        if (isset($this->singletons[$name])) {
            $this->instances[$name] = $instance;
        }

        return $instance;
    }

    public function has(string $name): bool
    {
        return isset($this->factories[$name]) || isset($this->instances[$name]);
    }

    /**
     * 清除已解析的 singleton 实例（保留工厂）。主要用于测试。
     */
    public function flushInstances(): void
    {
        $this->instances = [];
    }

    /**
     * 注册核心服务默认工厂。
     *
     * 工厂内部调用 ::getInstance() 以保持与现有代码一致，
     * 待 Service 构造函数改造后再切换为真正注入依赖。
     */
    private function registerDefaultServices(): void
    {
        $this->singleton('db', function () {
            return Database::getInstance();
        });

        $this->singleton('config', function () {
            return Config::getInstance();
        });

        $this->singleton('concurrency', function () {
            return ConcurrencyGuard::getInstance();
        });

        $this->singleton('rateLimiter', function () {
            return AdaptiveRateLimiter::getInstance();
        });

        $this->singleton('auth', function () {
            return new AuthService();
        });

        $this->singleton('files', function () {
            return new FileManagerService();
        });

        $this->singleton('share', function () {
            return new ShareService();
        });

        $this->singleton('fileQuery', function () {
            return new FileQueryService();
        });

        $this->singleton('fileEncryption', function () {
            return new FileEncryptionService();
        });

        $this->singleton('fileFavorite', function () {
            return new FileFavoriteService();
        });

        $this->singleton('fileAccess', function () {
            return new FileAccessService();
        });

        $this->singleton('aiConfig', function () {
            return new \App\Services\AIConfigService();
        });

        $this->singleton('aiSession', function () {
            return new \App\Services\AISessionService();
        });

        $this->singleton('aiTool', function () {
            return new \App\Services\AIToolService();
        });
    }
}
