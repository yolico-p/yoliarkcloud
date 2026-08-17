<?php

namespace App\Core;

use App\Services\AuthService;

/**
 * 中间件抽象 — 统一 CSRF / 鉴权 / 限流入口。
 *
 * 解决原架构中这些检查散落在 api.php 顶层、BaseController、控制器内 3 处的问题。
 * 调用方通过 Middleware::csrf() / Middleware::auth() / Middleware::rateLimit()
 * 显式声明所需检查，不再依赖开发者自觉。
 *
 * 设计为静态方法而非接口链，原因是：
 * 1. 项目无依赖注入容器，链式中间件需引入大量基础设施
 * 2. 现有控制器已用 $this->validateCSRF() / $this->requireAuth() 显式调用
 * 3. 静态方法可直接被 api.php 与 BaseController 复用，迁移成本最低
 */
class Middleware
{
    /**
     * CSRF 校验。
     *
     * POST/PUT/PATCH/DELETE 请求必须携带有效 token，否则 403。
     * GET/OPTIONS/HEAD 跳过（语义安全方法）。
     */
    public static function csrf(?Request $request = null): void
    {
        $request ??= Request::current();
        $method = $request->method();
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        $token = $request->csrfToken();
        if (!Security::verifyCSRFToken($token)) {
            Security::jsonOutput(['success' => false, 'message' => 'CSRF验证失败，请刷新页面重试', 'code' => 403], 403);
        }
    }

    /**
     * 鉴权校验：调用 AuthService::isLoggedIn() 做完整指纹/过期校验。
     *
     * 与 BaseController::requireAuth() 不同，本方法走完整的 AuthService 链路，
     * 不再仅检查 $_SESSION['user_id'] 是否存在。
     */
    public static function auth(): void
    {
        $auth = new AuthService();
        if (!$auth->isLoggedIn()) {
            Security::jsonOutput(['success' => false, 'message' => '请先登录', 'code' => 401], 401);
        }
    }

    /**
     * 仅检查登录状态（不走指纹/过期校验），用于对会话新鲜度不敏感的场景。
     */
    public static function authSimple(): void
    {
        if (empty($_SESSION['user_id'])) {
            Security::jsonOutput(['success' => false, 'message' => '请先登录', 'code' => 401], 401);
        }
    }

    /**
     * 管理员权限校验。
     */
    public static function admin(): void
    {
        self::auth();
        $auth = new AuthService();
        if (!$auth->isAdmin()) {
            Security::jsonOutput(['success' => false, 'message' => '需要管理员权限'], 403);
        }
    }

    /**
     * 固定窗口限流（基于数据库表 + AdaptiveRateLimiter）。
     *
     * 替代原文件 flock 实现，避免高并发下的 IO 瓶颈。
     */
    public static function rateLimit(string $action, int $maxAttempts, int $decaySeconds, ?Request $request = null): void
    {
        $request ??= Request::current();
        $ip = Security::getClientIP();
        $key = "{$action}_{$ip}";

        if (!AdaptiveRateLimiter::getInstance()->adaptiveCheck($key, $maxAttempts, 0, $action)['allowed'] ?? false) {
            self::tooManyRequestsResponse($decaySeconds);
        }
    }

    /**
     * 自适应限流（按文件大小动态调整令牌桶）。
     */
    public static function adaptiveRateLimit(string $action, int $fileSize = 0): void
    {
        $userId = $_SESSION['user_id'] ?? 0;
        $result = Security::adaptiveRateLimit($action, $userId, $fileSize);

        if (empty($result['allowed'])) {
            $retryAfter = $result['retry_after'] ?? 30;
            header("Retry-After: {$retryAfter}");
            header('X-RateLimit-Tokens-Left: 0');
            header('X-RateLimit-Pattern: ' . ($result['pattern'] ?? 'unknown'));
            Security::jsonOutput([
                'success' => false,
                'message' => "请求过于频繁，请 {$retryAfter} 秒后再试",
                'retry_after' => $retryAfter,
            ], 429);
        }

        if (!empty($result['warning'])) {
            header('X-RateLimit-Warning: approaching limit');
        }
        if (!empty($result['slowdown_ms'])) {
            usleep($result['slowdown_ms'] * 1000);
        }

        header('X-RateLimit-Tokens-Left: ' . ($result['tokens_left'] ?? 0));
        header('X-RateLimit-Pattern: ' . ($result['pattern'] ?? 'unknown'));
        header('X-RateLimit-Usage: ' . round(($result['usage_ratio'] ?? 0) * 100) . '%');
    }

    /**
     * 软限流：扣减令牌并记录统计，但即使令牌不足也不拒绝请求。
     * 适用于分片上传等不能因限流中断的场景——拒绝会导致分片缺失。
     * 高负载时仍通过 slowdown_ms 延迟实现平滑降速，而非硬拒绝。
     */
    public static function adaptiveRateLimitSoft(string $action, int $fileSize = 0): void
    {
        $userId = $_SESSION['user_id'] ?? 0;
        $result = Security::adaptiveRateLimit($action, $userId, $fileSize);

        // 不拒绝：仅在高负载时添加延迟实现平滑降速
        if (!empty($result['slowdown_ms'])) {
            usleep($result['slowdown_ms'] * 1000);
        }

        header('X-RateLimit-Tokens-Left: ' . ($result['tokens_left'] ?? 0));
        header('X-RateLimit-Pattern: ' . ($result['pattern'] ?? 'unknown'));
        header('X-RateLimit-Usage: ' . round(($result['usage_ratio'] ?? 0) * 100) . '%');
    }

    /**
     * 标准化 429 响应。
     */
    private static function tooManyRequestsResponse(int $retryAfter): void
    {
        header("Retry-After: {$retryAfter}");
        Security::jsonOutput([
            'success' => false,
            'message' => '操作过于频繁，请稍后再试',
            'retry_after' => $retryAfter,
        ], 429);
    }
}
