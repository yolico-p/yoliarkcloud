<?php

namespace App\Services;

use App\Core\Config;

/**
 * 对接 YoliPanel（柚舟控制面板）的 API 客户端
 *
 * 用于设备注册和密码找回功能，所有请求走 HTTPS。
 * API Token 存储在 config.json 中，不在代码里。
 *
 * 端点格式:
 *   GET/POST  /v1/register                    — 无 Token（设备注册）
 *   POST      /v1/{token}/password-recovery   — Token 在 URL 路径中
 *   POST      /v1/{token}/verify-code         — Token 在 URL 路径中
 */
class MailApiService
{
    private ?string $apiUrl;
    private ?string $apiToken;
    private bool $enabled;

    public function __construct()
    {
        $config = Config::getInstance();
        $mailCfg = $config->get('mail_system', []);

        $this->apiUrl   = !empty($mailCfg['api_url']) ? rtrim($mailCfg['api_url'], '/') : null;
        $this->apiToken = $mailCfg['token'] ?? null;
        $this->enabled  = !empty($mailCfg['enabled']) && $this->apiUrl && $this->apiToken;
    }

    /**
     * 检查邮件系统是否可用
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * 向 YoliPanel 注册本设备（安装时调用）
     */
    public function register(string $deviceId): array
    {
        $payload = [
            'device_id'       => $deviceId,
            'version'         => PANCLOUD_VERSION,
            'php_version'     => PHP_VERSION,
            'server_os'       => PHP_OS_FAMILY,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'db_type'         => Config::getInstance()->get('database.type', 'sqlite'),
        ];

        return $this->call('POST', '/v1/register', $payload, false);
    }

    /**
     * 请求发送密码找回验证码
     *
     * POST /v1/{token}/password-recovery
     */
    public function sendRecoveryCode(string $email, string $deviceId): array
    {
        return $this->call('POST', '/v1/' . $this->apiToken . '/password-recovery', [
            'to'        => $email,
            'device_id' => $deviceId,
        ], false);
    }

    /**
     * 校验密码找回验证码
     *
     * POST /v1/{token}/verify-code
     */
    public function verifyCode(string $email, string $code, string $deviceId): array
    {
        return $this->call('POST', '/v1/' . $this->apiToken . '/verify-code', [
            'to'        => $email,
            'code'      => $code,
            'device_id' => $deviceId,
        ], false);
    }

    /**
     * 发送 HTTP 请求到 YoliPanel
     */
    private function call(string $method, string $path, array $data, bool $auth = true): array
    {
        if (!$this->apiUrl) {
            return ['success' => false, 'error' => '邮件系统未配置'];
        }

        $url = $this->apiUrl . $path;

        $headers = [
            'Content-Type: application/json',
        ];

        $context = stream_context_create([
            'http' => [
                'method'  => $method,
                'header'  => implode("\r\n", $headers),
                'content' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'timeout' => 10,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return ['success' => false, 'error' => '无法连接到邮件服务'];
        }

        $result = json_decode($response, true);
        if (!is_array($result)) {
            return ['success' => false, 'error' => '邮件服务响应异常'];
        }

        return $result;
    }
}
