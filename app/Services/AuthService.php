<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Core\Config;

class AuthService
{
    private $db;
    private $config;
    private $userCache = null;
    private $userIdCache = null;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = Config::getInstance();
    }

    public function login($username, $password)
    {
        $user = $this->db->fetch("SELECT * FROM users WHERE username = ?", [$username]);

        if (!$user) {
            return ['success' => false, 'message' => '用户名或密码错误'];
        }

        if (!Security::verifyPassword($password, $user['password_hash'])) {
            return ['success' => false, 'message' => '用户名或密码错误'];
        }

        // 检查密码哈希是否需要升级（bcrypt cost 变更后自动更新）
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $newHash = Security::hashPassword($password);
            $this->db->update('users', [
                'password_hash' => $newHash,
                'updated_at' => time(),
            ], 'id = ?', [$user['id']]);
        }

        // bootstrap/app.php 已启动 session；这里仅做会话固定防护
        $_SESSION = [];

        // 重新生成 session ID（删除旧 session 文件）
        session_regenerate_id(true);

        // 保存用户数据到新 session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['login_time'] = time();
        $_SESSION['login_ip'] = Security::getClientIP();
        $_SESSION['fingerprint'] = $this->generateFingerprint();
        $_SESSION['fingerprint_relaxed'] = $this->generateRelaxedFingerprint();
        $_SESSION['created_at'] = time();
        $_SESSION['session_renewed'] = true;
        $this->initEncryptionKey($user, $password);

        // 登录后立即轮换 CSRF token，防止会话固定攻击
        Security::rotateCSRFToken();

        $this->db->update('users', ['last_login' => time()], 'id = ?', [$user['id']]);
        // 登录成功后清除登录限流桶（AdaptiveRateLimiter 数据库令牌桶）
        \App\Core\AdaptiveRateLimiter::getInstance()->clearBucket('login_' . Security::getClientIP());

        $this->logOperation('login', '用户登录');

        return ['success' => true, 'message' => '登录成功'];
    }

    public function logout()
    {
        $this->logOperation('logout', '用户登出');

        $this->clearUserCache();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();
    }

    public function isLoggedIn()
    {
        if (empty($_SESSION['user_id'])) {
            return false;
        }

        if (time() - ($_SESSION['login_time'] ?? 0) > $this->config->get('session_lifetime')) {
            $this->logout();
            return false;
        }

        if ($_SESSION['fingerprint'] !== $this->generateFingerprint()) {
            // 严格指纹不匹配，尝试降级校验
            if (!$this->verifyFingerprint()) {
                $this->logout();
                return false;
            }
        }

        if (isset($_SESSION['created_at']) && (time() - $_SESSION['created_at']) > ($this->config->get('session_lifetime') * 2)) {
            $this->logout();
            return false;
        }

        return true;
    }

    public function requireAuth()
    {
        if (!$this->isLoggedIn()) {
            if (Security::isAjax()) {
                Security::jsonOutput(['error' => '未登录或会话已过期'], 401);
            }
            Security::redirect('index.php?page=login');
        }
    }

    public function getUser()
    {
        if ($this->userCache !== null) {
            return $this->userCache;
        }

        if (!$this->isLoggedIn()) {
            return null;
        }

        $this->userCache = $this->db->fetch("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);
        return $this->userCache;
    }

    public function getUserId()
    {
        if ($this->userIdCache !== null) {
            return $this->userIdCache;
        }

        $this->userIdCache = $_SESSION['user_id'] ?? null;
        return $this->userIdCache;
    }

    public function clearUserCache()
    {
        $this->userCache = null;
        $this->userIdCache = null;
    }

    public function changePassword($oldPassword, $newPassword)
    {
        $user = $this->getUser();
        if (!$user) {
            return ['success' => false, 'message' => '用户不存在'];
        }

        if (!Security::verifyPassword($oldPassword, $user['password_hash'])) {
            return ['success' => false, 'message' => '原密码错误'];
        }

        if (strlen($newPassword) < $this->config->get('password_min_length')) {
            return ['success' => false, 'message' => '新密码长度不能少于' . $this->config->get('password_min_length') . '位'];
        }

        $newHash = Security::hashPassword($newPassword);
        $this->db->update('users', ['password_hash' => $newHash, 'updated_at' => time()], 'id = ?', [$user['id']]);

        $this->logOperation('change_password', '修改密码');

        return ['success' => true, 'message' => '密码修改成功'];
    }

    public function updateProfile($data)
    {
        $user = $this->getUser();
        if (!$user) {
            return ['success' => false, 'message' => '用户不存在'];
        }

        $updateData = ['updated_at' => time()];

        if (isset($data['email'])) {
            $updateData['email'] = trim($data['email']);
        }

        if (isset($data['storage_limit'])) {
            $limit = intval($data['storage_limit']);
            if ($limit > 0) {
                $updateData['storage_limit'] = $limit;
            }
        }

        $this->db->update('users', $updateData, 'id = ?', [$user['id']]);

        $this->logOperation('update_profile', '更新个人资料');

        return ['success' => true, 'message' => '资料更新成功'];
    }

    public function createUser($username, $password, $email = '', $role = 'user')
    {
        $existing = $this->db->fetch("SELECT id FROM users WHERE username = ?", [$username]);
        if ($existing) {
            return ['success' => false, 'message' => '用户名已存在'];
        }

        if (strlen($password) < $this->config->get('password_min_length')) {
            return ['success' => false, 'message' => '密码长度不能少于' . $this->config->get('password_min_length') . '位'];
        }

        $now = time();
        $this->db->insert('users', [
            'username' => $username,
            'password_hash' => Security::hashPassword($password),
            'email' => $email,
            'role' => $role,
            'storage_limit' => $this->config->get('max_upload_size') * 20,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['success' => true, 'message' => '用户创建成功'];
    }

    public function hasRole($role)
    {
        $user = $this->getUser();
        if (!$user) return false;
        return ($user['role'] ?? '') === $role;
    }

    public function isAdmin()
    {
        return $this->hasRole('admin');
    }

    public function checkStorageLimit($additionalBytes = 0)
    {
        $userId = $this->getUserId();
        if (!$userId) {
            return ['status' => false, 'reason' => 'user_not_found', 'message' => '用户不存在'];
        }

        // ── 实时计算已用空间，避免 users 表热点行竞争 ──
        $result = $this->db->fetch(
            "SELECT COALESCE(SUM(filesize), 0) as total_used, (SELECT storage_limit FROM users WHERE id = ?) as storage_limit FROM files WHERE user_id = ? AND is_dir = 0",
            [$userId, $userId]
        );
        if (!$result || !isset($result['storage_limit'])) {
            return ['status' => false, 'reason' => 'user_not_found', 'message' => '用户不存在'];
        }
        if (($result['total_used'] + $additionalBytes) > $result['storage_limit']) {
            return ['status' => false, 'reason' => 'storage_exceeded', 'message' => '存储空间不足'];
        }
        return ['status' => true, 'reason' => 'ok', 'message' => ''];
    }

    public function updateStorageUsed($bytes, $increase = true)
    {
        // 已废弃：storage_used 列在 v2 迁移已删除，改为实时聚合查询。
        // 调用方应直接使用 checkStorageLimit/getStorageUsed。
        // 保留空方法仅为兼容尚未清理的旧调用路径。
        return;
    }

    public function getRemainingStorage()
    {
        $userId = $this->getUserId();
        if (!$userId) {
            return 0;
        }

        $result = $this->db->fetch(
            "SELECT COALESCE(SUM(filesize), 0) as total_used, (SELECT storage_limit FROM users WHERE id = ?) as storage_limit FROM files WHERE user_id = ? AND is_dir = 0",
            [$userId, $userId]
        );
        if (!$result) {
            return 0;
        }
        return max(0, $result['storage_limit'] - $result['total_used']);
    }

    public function getStorageUsed()
    {
        $userId = $this->getUserId();
        if (!$userId) {
            return 0;
        }

        $result = $this->db->fetch(
            "SELECT COALESCE(SUM(filesize), 0) as total_used FROM files WHERE user_id = ? AND is_dir = 0",
            [$userId]
        );
        return $result ? intval($result['total_used']) : 0;
    }

    public function getEncryptionKey()
    {
        if (empty($_SESSION['enc_key'])) return null;
        $key = base64_decode($_SESSION['enc_key']);
        if ($key === false || strlen($key) !== 32) return null;
        return $key;
    }

    public function hasEncryptionKey()
    {
        return !empty($_SESSION['enc_key']);
    }

    private function generateFingerprint()
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

        // 不包含 IP 地址：避免移动网络切换 / VPN 变化时误登出
        $fingerprintData = $ua . '|' . $language;
        return hash('sha256', $fingerprintData);
    }

    /**
     * 生成简化指纹：仅取浏览器族 + OS 族 + 主语言。
     * 用于严格指纹校验失败后的降级校验，
     * 兼容移动端浏览器在请求间 UA/语言微变的场景。
     */
    private function generateRelaxedFingerprint()
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

        // 提取浏览器族：Chrome / Safari / Firefox / Edge / Opera 等
        $browserFamily = 'unknown';
        if (preg_match('#\bOPR/|Opera#i', $ua)) {
            $browserFamily = 'opera';
        } elseif (preg_match('#\bEdg/#i', $ua)) {
            $browserFamily = 'edge';
        } elseif (preg_match('#\bChrome/#i', $ua)) {
            $browserFamily = 'chrome';
        } elseif (preg_match('#\bFirefox/#i', $ua)) {
            $browserFamily = 'firefox';
        } elseif (preg_match('#\bSafari/#i', $ua) && !preg_match('#\bChrome/#i', $ua)) {
            $browserFamily = 'safari';
        }

        // 提取操作系统族：Android / iOS / Windows / macOS / Linux 等
        $osFamily = 'unknown';
        if (preg_match('#\bAndroid\b#i', $ua)) {
            $osFamily = 'android';
        } elseif (preg_match('#\biPhone\b|\biPad\b|\biPod\b#i', $ua)) {
            $osFamily = 'ios';
        } elseif (preg_match('#\bWindows\b#i', $ua)) {
            $osFamily = 'windows';
        } elseif (preg_match('#\bMacintosh\b|\bMac OS X\b#i', $ua)) {
            $osFamily = 'macos';
        } elseif (preg_match('#\bLinux\b#i', $ua)) {
            $osFamily = 'linux';
        }

        // Accept-Language 仅取主语言（逗号前的部分），避免权重微调导致指纹变化
        $primaryLang = strtolower(explode(',', $language)[0] ?? '');

        $fingerprintData = $browserFamily . '|' . $osFamily . '|' . $primaryLang;
        return hash('sha256', $fingerprintData);
    }

    /**
     * 校验会话指纹：先严格（完整 UA + 语言），失败后降级到简化（浏览器族 + OS 族 + 主语言）。
     * 降级通过时滚动更新严格指纹，保证后续请求仍优先走严格路径。
     */
    private function verifyFingerprint()
    {
        $currentStrict = $this->generateFingerprint();

        // 优先严格匹配
        if (isset($_SESSION['fingerprint']) && hash_equals($_SESSION['fingerprint'], $currentStrict)) {
            return true;
        }

        // 严格不通过，尝试降级：简化指纹匹配
        $currentRelaxed = $this->generateRelaxedFingerprint();
        $storedRelaxed = $_SESSION['fingerprint_relaxed'] ?? null;

        if ($storedRelaxed !== null && hash_equals($storedRelaxed, $currentRelaxed)) {
            // 降级通过：UA/语言发生了细微变化但浏览器族+OS 未变，属于移动端正常行为。
            // 滚动更新严格指纹，后续请求可重新走严格路径。
            $_SESSION['fingerprint'] = $currentStrict;
            $_SESSION['fingerprint_relaxed'] = $currentRelaxed;
            return true;
        }

        // 严格和降级均不通过，判定为会话劫持
        return false;
    }

    private function initEncryptionKey($user, $password)
    {
        if (empty($user['encryption_key'])) {
            $rawKey = random_bytes(32);
            $derivedKey = $this->deriveKeyFromPassword($password, $user['username']);
            $iv = random_bytes(16);
            $encryptedKey = openssl_encrypt($rawKey, 'AES-256-CBC', $derivedKey, OPENSSL_RAW_DATA, $iv);
            if ($encryptedKey === false) return;
            $this->db->update('users', [
                'encryption_key' => base64_encode($iv . $encryptedKey),
                'updated_at' => time(),
            ], 'id = ?', [$user['id']]);
            $_SESSION['enc_key'] = base64_encode($rawKey);
        } else {
            $derivedKey = $this->deriveKeyFromPassword($password, $user['username']);
            $data = base64_decode($user['encryption_key']);
            if ($data === false || strlen($data) < 32) return;
            $iv = substr($data, 0, 16);
            $encryptedKey = substr($data, 16);
            $rawKey = openssl_decrypt($encryptedKey, 'AES-256-CBC', $derivedKey, OPENSSL_RAW_DATA, $iv);
            if ($rawKey === false) return;
            $_SESSION['enc_key'] = base64_encode($rawKey);
        }
    }

    private function deriveKeyFromPassword($password, $salt)
    {
        if (!function_exists('hash_pbkdf2')) {
            return hash('sha256', $password . $salt, true);
        }
        return hash_pbkdf2('sha256', $password, $salt, 100000, 32, true);
    }

    private function logOperation($action, $detail = '')
    {
        if (!$this->getUserId()) {
            return;
        }

        $category = $this->getLogCategory($action);
        $severity = $this->getLogSeverity($action);
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $this->db->insert('operation_logs', [
            'user_id' => $this->getUserId(),
            'action' => $action,
            'category' => $category,
            'severity' => $severity,
            'detail' => $detail,
            'ip' => Security::getClientIP(),
            'user_agent' => $userAgent,
            'created_at' => time(),
        ]);
    }

    private function getLogCategory($action)
    {
        $categories = [
            'login' => 'auth', 'logout' => 'auth', 'change_password' => 'auth', 'register' => 'auth',
            'upload' => 'file', 'upload_chunk' => 'file', 'download' => 'file', 'delete' => 'file',
            'rename' => 'file', 'move' => 'file', 'copy' => 'file', 'create_folder' => 'file',
            'restore' => 'file', 'permanent_delete' => 'file', 'empty_trash' => 'file', 'toggle_favorite' => 'file',
            'create_share' => 'share', 'delete_share' => 'share', 'toggle_share' => 'share',
            'update_profile' => 'account', 'update_config' => 'system', 'clear_cache' => 'system', 'clear_logs' => 'system',
        ];
        return $categories[$action] ?? 'other';
    }

    private function getLogSeverity($action)
    {
        $critical = ['permanent_delete', 'empty_trash', 'change_password'];
        $warning = ['delete', 'batch_delete', 'login', 'update_config', 'clear_logs'];
        if (in_array($action, $critical)) return 'critical';
        if (in_array($action, $warning)) return 'warning';
        return 'info';
    }
}
