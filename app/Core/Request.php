<?php

namespace App\Core;

/**
 * HTTP Request 对象封装。
 *
 * 取代控制器和 Service 直接读取 $_GET / $_POST / $_SERVER / file_get_contents('php://input')，
 * 提升可测试性，并集中处理 JSON body 解析、CSRF token 提取等通用逻辑。
 */
class Request
{
    private static ?Request $current = null;

    private array $query;
    private array $post;
    private array $server;
    private array $files;
    private array $cookies;
    private ?array $jsonBody = null;
    private bool $jsonParsed = false;

    public function __construct(
        ?array $query = null,
        ?array $post = null,
        ?array $server = null,
        ?array $files = null,
        ?array $cookies = null
    ) {
        $this->query = $query ?? $_GET;
        $this->post = $post ?? $_POST;
        $this->server = $server ?? $_SERVER;
        $this->files = $files ?? $_FILES;
        $this->cookies = $cookies ?? $_COOKIE;
    }

    /**
     * 获取当前请求的单例（基于 PHP 超全局变量）。
     */
    public static function current(): self
    {
        if (self::$current === null) {
            self::$current = new self();
        }
        return self::$current;
    }

    /**
     * 从任意来源取值：POST > GET > JSON body。
     */
    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }
        $json = $this->getJsonBody();
        if (is_array($json) && array_key_exists($key, $json)) {
            return $json[$key];
        }
        return $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function files(): array
    {
        return $this->files;
    }

    public function cookies(): array
    {
        return $this->cookies;
    }

    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    public function isPost(): bool
    {
        return $this->isMethod('POST');
    }

    public function isJson(): bool
    {
        $contentType = $this->server['CONTENT_TYPE'] ?? '';
        return stripos($contentType, 'application/json') !== false;
    }

    /**
     * 解析 JSON body（仅在第一次访问时解析，后续缓存）。
     * 与 api.php 顶层共享缓存，避免重复 file_get_contents('php://input')。
     */
    public function getJsonBody(): ?array
    {
        if ($this->jsonParsed) {
            return $this->jsonBody;
        }
        $this->jsonParsed = true;

        // 优先使用 api.php 已解析的全局缓存
        if (isset($GLOBALS['_PANCLOUD_JSON_BODY']) && is_array($GLOBALS['_PANCLOUD_JSON_BODY'])) {
            $this->jsonBody = $GLOBALS['_PANCLOUD_JSON_BODY'];
            return $this->jsonBody;
        }

        if (!$this->isJson()) {
            $this->jsonBody = null;
            return null;
        }

        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            $this->jsonBody = [];
            $GLOBALS['_PANCLOUD_JSON_BODY'] = [];
            return $this->jsonBody;
        }

        $decoded = json_decode($raw, true);
        $this->jsonBody = is_array($decoded) ? $decoded : [];
        $GLOBALS['_PANCLOUD_JSON_BODY'] = $this->jsonBody;
        return $this->jsonBody;
    }

    /**
     * 提取 CSRF token：POST body > X-CSRF-TOKEN > X-XSRF-TOKEN > JSON body。
     */
    public function csrfToken(): string
    {
        $token = $this->post['_csrf_token']
            ?? $this->server['HTTP_X_CSRF_TOKEN']
            ?? $this->server['HTTP_X_XSRF_TOKEN']
            ?? '';

        if (empty($token)) {
            $json = $this->getJsonBody();
            $token = $json['_csrf_token'] ?? '';
        }
        return is_string($token) ? $token : '';
    }

    /**
     * 解析批量操作的 ID 列表，统一转为 int[]。
     * 支持 JSON 字符串、数组两种传入方式。
     */
    public function idList(string $key): array
    {
        $raw = $this->input($key, []);
        if (is_array($raw)) {
            return array_map('intval', array_filter($raw, 'is_numeric'));
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_map('intval', array_filter($decoded, 'is_numeric'));
            }
        }
        return [];
    }

    /**
     * 取整数输入，带可选边界检查。
     */
    public function intInput(string $key, int $default = 0, ?int $min = null, ?int $max = null): int
    {
        $val = $this->input($key);
        if ($val === null) return $default;
        $int = (int)$val;
        if ($min !== null && $int < $min) return $min;
        if ($max !== null && $int > $max) return $max;
        return $int;
    }
}
