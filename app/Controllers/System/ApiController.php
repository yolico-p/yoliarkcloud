<?php

namespace App\Controllers\System;

use App\Controllers\BaseController;
use App\Core\Security;
use App\Core\Config;

class ApiController extends BaseController
{
    private function getApiConfig()
    {
        $config = Config::getInstance();
        return [
            'enabled' => $config->get('api_enabled', false),
            'token' => $config->get('api_token', ''),
            'token_created_at' => $config->get('api_token_created_at', 0),
            'rate_limit' => $config->get('api_rate_limit', 60),
            'rate_window' => $config->get('api_rate_window', 60),
        ];
    }

    public function getConfig()
    {
        $this->requireAdmin();

        $apiConfig = $this->getApiConfig();
        // 不返回完整 token，只返回是否存在
        $apiConfig['has_token'] = !empty($apiConfig['token']);
        unset($apiConfig['token']);

        Security::jsonOutput(['success' => true, 'api' => $apiConfig]);
    }

    public function toggleApi()
    {
        $this->requireAdmin();
        $this->validateCSRF();

        $enabled = $this->input('enabled', false);
        $config = Config::getInstance();
        $config->set('api_enabled', (bool)$enabled);

        if (!$enabled) {
            // 关闭 API 时清除 token
            $config->set('api_token', '');
            $config->set('api_token_created_at', 0);
        }

        $config->save();

        $this->logOperation('toggle_api', $enabled ? '开启 API' : '关闭 API');
        $this->success($enabled ? 'API 已开启' : 'API 已关闭');
    }

    public function generateToken()
    {
        $this->requireAdmin();
        $this->validateCSRF();

        $config = Config::getInstance();

        if (!$config->get('api_enabled', false)) {
            $this->error('请先开启 API 功能');
        }

        // 验证当前密码
        $password = $this->input('_password', '');
        if (empty($password)) {
            $this->error('生成 Token 需要输入当前密码确认');
        }
        $user = $this->db->fetch("SELECT password_hash FROM users WHERE id = ?", [$this->getUserId()]);
        if (!$user || !Security::verifyPassword($password, $user['password_hash'])) {
            $this->error('密码验证失败');
        }

        // 生成 64 字节的安全 token
        $token = 'yac_' . bin2hex(random_bytes(48));
        $config->set('api_token', $token);
        $config->set('api_token_created_at', time());
        $config->save();

        $this->logOperation('generate_api_token', '生成新 API Token');

        // 只在生成时返回完整 token
        Security::jsonOutput([
            'success' => true,
            'message' => 'Token 已生成，请妥善保管，此 Token 仅显示一次',
            'token' => $token,
            'created_at' => time(),
        ]);
    }

    public function revokeToken()
    {
        $this->requireAdmin();
        $this->validateCSRF();

        $config = Config::getInstance();
        $config->set('api_token', '');
        $config->set('api_token_created_at', 0);
        $config->save();

        $this->logOperation('revoke_api_token', '撤销 API Token');
        $this->success('Token 已撤销');
    }

    public function updateApiConfig()
    {
        $this->requireAdmin();
        $this->validateCSRF();

        $config = Config::getInstance();
        $rateLimit = intval($this->input('api_rate_limit', 60));
        $rateWindow = intval($this->input('api_rate_window', 60));

        $config->set('api_rate_limit', max(1, min(1000, $rateLimit)));
        $config->set('api_rate_window', max(10, min(3600, $rateWindow)));
        $config->save();

        $this->logOperation('update_api_config', '更新 API 配置');
        $this->success('API 配置已更新');
    }

    /**
     * 验证 API Token（供外部 API 入口调用）
     */
    public static function verifyApiToken($token)
    {
        if (empty($token)) return false;

        $config = Config::getInstance();
        if (!$config->get('api_enabled', false)) return false;

        $storedToken = $config->get('api_token', '');
        if (empty($storedToken)) return false;

        return hash_equals($storedToken, $token);
    }

    private function logOperation($action, $detail = '')
    {
        $userId = $this->getUserId();
        if (!$userId) return;

        $this->db->insert('operation_logs', [
            'user_id' => $userId,
            'action' => $action,
            'category' => 'system',
            'severity' => 'warning',
            'detail' => $detail,
            'ip' => Security::getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => time(),
        ]);
    }
}
