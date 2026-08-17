<?php

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Core\Security;
use App\Core\Config;
use App\Services\AuthService;
use App\Services\MailApiService;

class LoginController extends BaseController
{
    public function login()
    {
        $username = $this->input('username', '');
        $password = $this->input('password', '');

        if (empty($username) || empty($password)) {
            return $this->error('用户名和密码不能为空');
        }

        $uaScore = Security::getUAScore();
        $config = Config::getInstance();
        $maxAttempts = $config->get('login_max_attempts', 5);
        $lockoutTime = $config->get('login_lockout_time', 900);

        if ($uaScore < 50) {
            $maxAttempts = max(3, intval($maxAttempts * 0.6));
            $lockoutTime = intval($lockoutTime * 1.5);
        }

        $this->rateLimit('login', $maxAttempts, $lockoutTime);

        $auth = new AuthService();
        $result = $auth->login($username, $password);

        if ($result['success']) {
            $this->success($result['message'], [
                'user_id' => $_SESSION['user_id'],
                'username' => $_SESSION['username'],
            ]);
        } else {
            $this->error($result['message']);
        }
    }

    public function logout()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $auth = new AuthService();
        $auth->logout();

        $this->success('已退出登录');
    }

    /**
     * 忘记密码 — 发送验证码
     *
     * POST api.php?action=forgot_password
     * Body: { email: "user@example.com" }
     */
    public function forgotPassword()
    {
        $email = $this->input('email', '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('请输入有效的邮箱地址');
        }

        // 速率限制：每分钟最多 3 次
        $this->rateLimit('forgot_password:' . $email, 3, 60);

        // 统一的返回信息（防枚举）
        $genericMsg = '如果该邮箱已注册，验证码已发送';

        // 先检查邮件系统是否配置（避免暴露邮箱状态）
        $mailApi = new MailApiService();
        if (!$mailApi->isEnabled()) {
            // 邮件未配置时返回统一消息，不暴露邮箱是否存在
            $this->success($genericMsg);
        }

        // 检查邮箱是否存在
        $user = \App\Models\User::findByEmail($email);
        if (!$user) {
            $this->success($genericMsg);
        }

        // 获取 device_id
        $config = Config::getInstance();
        $deviceId = $config->get('mail_system.device_id', '');

        // 调用 YoliPanel 发送验证码
        $result = $mailApi->sendRecoveryCode($email, $deviceId);

        if (!empty($result['success'])) {
            $this->success($genericMsg);
        }

        $this->error('验证码发送失败，请稍后重试');
    }

    /**
     * 验证重置码并更新密码
     *
     * POST api.php?action=verify_reset
     * Body: { email: "user@example.com", code: "85296374", password: "newPassword123!" }
     */
    public function verifyReset()
    {
        $email    = $this->input('email', '');
        $code     = $this->input('code', '');
        $password = $this->input('password', '');

        if (empty($email) || empty($code) || empty($password)) {
            $this->error('邮箱、验证码和新密码不能为空');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('邮箱格式无效');
        }

        // 验证码格式
        if (!preg_match('/^\d{8}$/', $code)) {
            $this->error('验证码格式无效（8位数字）');
        }

        // 密码长度检查
        $config = Config::getInstance();
        $minLen = $config->get('password_min_length', 8);
        if (strlen($password) < $minLen) {
            $this->error("密码长度不能少于 {$minLen} 位");
        }

        // 速率限制
        $this->rateLimit('verify_reset:' . $email, 5, 300);

        // 检查邮箱是否存在
        $user = \App\Models\User::findByEmail($email);
        if (!$user) {
            $this->error('验证失败，请重试');
        }

        // 校验验证码
        $mailApi = new MailApiService();
        if (!$mailApi->isEnabled()) {
            $this->error('密码找回功能暂未配置');
        }

        $deviceId = $config->get('mail_system.device_id', '');
        $result = $mailApi->verifyCode($email, $code, $deviceId);

        if (empty($result['success'])) {
            $this->error($result['message'] ?? '验证码错误');
        }

        // 验证通过，更新密码
        $db = \App\Core\Database::getInstance();
        $db->update('users', [
            'password_hash' => Security::hashPassword($password),
            'updated_at' => time(),
        ], 'id = ?', [$user->get('id')]);

        $this->success('密码修改成功');
    }
}
