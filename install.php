<?php
require_once __DIR__ . '/bootstrap/app.php';

use App\Core\Security;
use App\Core\Config;
use App\Core\Database;

// 安装页面强制开启错误显示，避免白屏无提示
error_reporting(E_ALL);
ini_set('display_errors', 1);

$config = Config::getInstance();

if ($config->isInstalled() && empty($_SESSION['install_security_pending'])) {
    Security::redirect('index.php');
}

$showSecurityPage = !empty($_SESSION['install_security_pending']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'finish_security') {
    $checkFiles = $_SESSION['install_check_files'] ?? [];
    foreach ($checkFiles as $file) {
        @unlink($file);
    }
    unset($_SESSION['install_security_pending'], $_SESSION['install_check_files'], $_SESSION['install_check_paths']);

    // 关键修复：释放 Session 文件锁，避免阻塞登录
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    header('Location: index.php?page=login');
    exit;
}

define('INSTALL_TOKEN_FILE', DATA_PATH . DIRECTORY_SEPARATOR . '.install_token');

// 生成并持久化安装令牌（同时写入 session 与文件，双保险）
function regenerateInstallToken(): string
{
    $token = bin2hex(random_bytes(32));
    if (!is_dir(DATA_PATH)) {
        @mkdir(DATA_PATH, 0755, true);
    }
    @file_put_contents(INSTALL_TOKEN_FILE, $token, LOCK_EX);
    $_SESSION['install_token'] = $token;
    return $token;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $installToken = regenerateInstallToken();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['install_token'] ?? '';
    // 优先从 session 验证（不依赖文件权限），文件作为后备
    $sessionToken = $_SESSION['install_token'] ?? '';
    $sessionMatch = !empty($sessionToken) && hash_equals($sessionToken, $token);
    $tokenFileExists = file_exists(INSTALL_TOKEN_FILE);
    $tokenFileContent = $tokenFileExists ? trim(file_get_contents(INSTALL_TOKEN_FILE)) : '';
    $fileMatch = $tokenFileExists && !empty($tokenFileContent) && hash_equals($tokenFileContent, $token);
    $tokenMatch = $sessionMatch || $fileMatch;

    if (!$tokenMatch) {
        $error = '安装验证失败，请直接点击“开始安装”重新提交';
        // 关键修复：失败后立即重置令牌并写回表单，避免用户必须手动刷新才能重试
        $installToken = regenerateInstallToken();
    } else {
        // 令牌校验通过：保留当前令牌回填表单，确保后续字段校验失败时用户可直接修正后重试
        $installToken = $token;
        $dbType = $_POST['db_type'] ?? 'sqlite';
        $dbConfig = [
            'host' => trim($_POST['db_host'] ?? '127.0.0.1'),
            'port' => intval($_POST['db_port'] ?? 3306),
            'database' => trim($_POST['db_database'] ?? 'pancloud'),
            'username' => trim($_POST['db_username'] ?? 'root'),
            'password' => $_POST['db_password'] ?? '',
            'charset' => trim($_POST['db_charset'] ?? 'utf8mb4'),
        ];

        if ($dbType === 'pgsql') {
            $dbConfig['port'] = intval($_POST['db_port'] ?? 5432);
            $dbConfig['username'] = trim($_POST['db_username'] ?? 'postgres');
        }

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $email = trim($_POST['email'] ?? '');

        if (empty($username) || empty($password)) {
            $error = '用户名和密码不能为空';
        } elseif (strlen($username) < 3 || strlen($username) > 32) {
            $error = '用户名长度应为 3-32 个字符';
        } elseif (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u', $username)) {
            $error = '用户名只能包含字母、数字、下划线和中文';
        } elseif (strlen($password) < 8) {
            $error = '密码长度不能少于 8 位';
        } elseif ($password !== $confirmPassword) {
            $error = '两次输入的密码不一致';
        } else {
            if (!is_dir(DATA_PATH)) mkdir(DATA_PATH, 0755, true);
            if (!is_dir(FILES_PATH)) mkdir(FILES_PATH, 0755, true);
            if (!is_dir(TRASH_PATH)) mkdir(TRASH_PATH, 0755, true);
            if (!is_dir(UPLOAD_PATH)) mkdir(UPLOAD_PATH, 0755, true);

            $htaccessContent = "Deny from all\n";
            foreach ([FILES_PATH, TRASH_PATH, UPLOAD_PATH, DATA_PATH] as $dir) {
                $htFile = $dir . DIRECTORY_SEPARATOR . '.htaccess';
                if (!file_exists($htFile)) {
                    file_put_contents($htFile, $htaccessContent);
                }
            }

            // .filemap 已在 bootstrap/app.php 首次运行时生成，此处无需重复创建
            // （早期版本在此处创建会导致 CONFIG_FILE/DB_PATH 常量与 .filemap 不一致）

            $dbTestError = null;
            if ($dbType !== 'sqlite') {
                try {
                    $pdo = null;
                    if ($dbType === 'mysql') {
                        $dbHost = $dbConfig['host'] ?? '127.0.0.1';
                        $dbPort = $dbConfig['port'] ?? 3306;
                        $dbDatabase = $dbConfig['database'] ?? 'pancloud';
                        $dbUser = $dbConfig['username'] ?? 'root';
                        $dbPass = $dbConfig['password'] ?? '';
                        $dbCharset = $dbConfig['charset'] ?? 'utf8mb4';

                        $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbDatabase};charset={$dbCharset}";
                        $options = [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES => false,
                        ];
                        $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
                        $stmt = $pdo->query("SELECT 1");
                        $stmt->fetch();
                    } elseif ($dbType === 'pgsql') {
                        $dbHost = $dbConfig['host'] ?? '127.0.0.1';
                        $dbPort = $dbConfig['port'] ?? 5432;
                        $dbDatabase = $dbConfig['database'] ?? 'pancloud';
                        $dbUser = $dbConfig['username'] ?? 'postgres';
                        $dbPass = $dbConfig['password'] ?? '';

                        $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbDatabase}";
                        $options = [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES => false,
                        ];
                        $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
                        $stmt = $pdo->query("SELECT 1");
                        $stmt->fetch();
                    }
                } catch (\Throwable $e) {
                    $dbTestError = '数据库连接失败：' . $e->getMessage();
                }
            }

            if ($dbTestError !== null) {
                $error = $dbTestError;
            } else {
                $config = Config::getInstance();
                $config->set('database.type', $dbType);
                $config->set('database.config', $dbConfig);
                $config->save();

                @unlink(INSTALL_TOKEN_FILE);
                unset($_SESSION['install_token']);

                // 数据库初始化与建表、管理员写入：失败时回滚配置，避免半安装状态
                try {
                    $db = Database::getInstance();

                    try {
                        $benchmark = new \App\Core\ServerBenchmark();
                        $benchmark->run();
                    } catch (\Throwable $e) {
                    }

                    $now = time();

                    $diskFree = @disk_free_space(ROOT_PATH);
                    $diskTotal = @disk_total_space(ROOT_PATH);
                    if ($diskFree === false || $diskTotal === false) {
                        $storageLimit = 10737418240;
                    } else {
                        $reserveBytes = 500 * 1024 * 1024;
                        $storageLimit = max(104857600, min(max(0, $diskFree - $reserveBytes), min($diskTotal, 1099511627776)));
                    }
                    $db->insert('users', [
                        'username' => $username,
                        'password_hash' => Security::hashPassword($password),
                        'email' => $email,
                        'role' => 'admin',
                        'storage_limit' => $storageLimit,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } catch (\Throwable $e) {
                    // 回滚：删除已保存的配置文件，使 isInstalled() 返回 false，允许重试
                    @unlink(CONFIG_FILE);
                    // 清理可能残留的 schema 标志，避免下次跳过建表
                    @unlink(DATA_PATH . DIRECTORY_SEPARATOR . '.schema_initialized');
                    @unlink(DATA_PATH . DIRECTORY_SEPARATOR . '.schema_version');
                    // 重置 Database 单例，使其下次重新初始化
                    \App\Core\Database::resetInstance();
                    $error = '数据库初始化失败：' . $e->getMessage();
                    $installToken = regenerateInstallToken();
                    // 跳过后续安装步骤，回到表单让用户重试
                    $dbInitFailed = true;
                }

                if (!isset($dbInitFailed)) {
                    $config->save();

                    // ── 设备注册（向 YoliPanel 注册本实例）──
                    $enableTelemetry = !empty($_POST['telemetry']);
                    if ($enableTelemetry) {
                        try {
                            $deviceId = bin2hex(random_bytes(16));
                            $payload = json_encode([
                                'device_id'       => $deviceId,
                                'version'         => defined('PANCLOUD_VERSION') ? PANCLOUD_VERSION : '1.0.0',
                                'php_version'     => PHP_VERSION,
                                'server_os'       => PHP_OS_FAMILY,
                                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
                                'db_type'         => $dbType,
                            ]);

                            $regCtx = stream_context_create([
                                'http' => [
                                    'method'  => 'POST',
                                    'header'  => "Content-Type: application/json\r\n",
                                    'content' => $payload,
                                    'timeout' => 5,
                                    'ignore_errors' => true,
                                ],
                                'ssl' => [
                                    'verify_peer'      => true,
                                    'verify_peer_name' => true,
                                ],
                            ]);

                            $regUrl = 'https://yoliarkpanelapi.hiyanyi.top/v1/register';
                            $regRes = @file_get_contents($regUrl, false, $regCtx);

                            if ($regRes !== false) {
                                $regData = json_decode($regRes, true);
                                if (!empty($regData['token'])) {
                                    $config->set('mail_system.api_url', 'https://yoliarkpanelapi.hiyanyi.top');
                                    $config->set('mail_system.token', $regData['token']);
                                    $config->set('mail_system.enabled', true);
                                    $config->set('mail_system.device_id', $deviceId);
                                    $config->save();
                                }
                            }
                        } catch (\Throwable $e) {
                            // 静默失败，不阻塞安装
                            error_log('[Install] 设备注册失败: ' . $e->getMessage());
                        }
                    }

                    $checkFiles = [];
                    $checkPaths = [];
                    $securityDirs = [
                        [STORAGE_PATH, '/storage/', '数据库/配置/用户文件'],
                        [UPLOAD_PATH, '/uploads/', '上传临时目录'],
                    ];
                    foreach ($securityDirs as $sd) {
                        $cf = $sd[0] . DIRECTORY_SEPARATOR . 'access_check.txt';
                        @file_put_contents($cf, 'security_check');
                        $checkFiles[] = $cf;
                        $checkPaths[] = ['url' => ltrim($sd[1], '/') . 'access_check.txt', 'label' => $sd[1], 'desc' => $sd[2]];
                    }
                    $_SESSION['install_security_pending'] = true;
                    $_SESSION['install_check_files'] = $checkFiles;
                    $_SESSION['install_check_paths'] = $checkPaths;
                    $showSecurityPage = true;
                    $success = true;
                    $installToken = '';
                } // end if (!isset($dbInitFailed))
            }
        }
    }
} else {
    $installToken = $installToken ?? '';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>安装 柚舟Cloud</title>
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
    <style>
        :root {
            /* UI 主色 - 自然蓝 */
            --harmony-primary: #007DFF;
            --harmony-primary-hover: #0066CC;
            --harmony-primary-light: #E6F2FF;
            --harmony-primary-pressed: #0059B3;

            /* UI 辅助色 - 自然色调 */
            --harmony-accent: #00C7BE;
            --harmony-accent-light: #E0F8F7;
            --harmony-warning: #FF9500;
            --harmony-error: #FA2C2C;
            --harmony-success: #34C759;

            /* UI 中性色 - 自然灰 */
            --harmony-black: #000000;
            --harmony-gray-1: #333333;
            --harmony-gray-2: #666666;
            --harmony-gray-3: #999999;
            --harmony-gray-4: #CCCCCC;
            --harmony-gray-5: #E5E5E5;
            --harmony-gray-6: #F2F2F2;
            --harmony-white: #FFFFFF;

            /* UI 背景色 */
            --harmony-bg-primary: #F1F3F5;
            --harmony-bg-secondary: #FFFFFF;
            --harmony-bg-tertiary: #F7F8FA;

            /* UI 文字色 */
            --harmony-text-primary: #121212;
            --harmony-text-secondary: #666666;
            --harmony-text-tertiary: #999999;
            --harmony-text-fourth: #CCCCCC;

            /* UI 圆角 - 连续曲率 */
            --harmony-radius-sm: 8px;
            --harmony-radius-md: 12px;
            --harmony-radius-lg: 16px;
            --harmony-radius-xl: 20px;
            --harmony-radius-xxl: 24px;

            /* UI 阴影 - 自然光影 */
            --harmony-shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.04);
            --harmony-shadow-md: 0 4px 16px rgba(0, 0, 0, 0.06);
            --harmony-shadow-lg: 0 8px 32px rgba(0, 0, 0, 0.08);

            /* UI 间距 */
            --harmony-space-xs: 4px;
            --harmony-space-sm: 8px;
            --harmony-space-md: 12px;
            --harmony-space-lg: 16px;
            --harmony-space-xl: 20px;
            --harmony-space-xxl: 24px;
            --harmony-space-xxxl: 32px;

            /* UI 动效 - 自然流畅 */
            --harmony-duration-fast: 150ms;
            --harmony-duration-normal: 250ms;
            --harmony-duration-slow: 350ms;
            --harmony-easing: cubic-bezier(0.4, 0.0, 0.2, 1);
            --harmony-easing-spring: cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        /* ========================================
           基础重置
           ======================================== */
        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            font-size: 16px;
            -webkit-text-size-adjust: 100%;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "HarmonyOS Sans", "PingFang SC", "Microsoft YaHei", sans-serif;
            line-height: 1.5;
            color: var(--harmony-text-primary);
            background: linear-gradient(180deg,
                    var(--harmony-bg-primary) 0%,
                    #E8EBF0 50%,
                    var(--harmony-bg-primary) 100%);
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: var(--harmony-space-xl);
        }

        .harmony-container {
            width: 100%;
            max-width: 1000px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            background: var(--harmony-bg-secondary);
            border-radius: var(--harmony-radius-xxl);
            box-shadow: var(--harmony-shadow-lg);
            overflow: hidden;
            animation: harmonyEnter var(--harmony-duration-slow) var(--harmony-easing-spring);
            min-height: 500px;
        }

        @keyframes harmonyEnter {
            from {
                opacity: 0;
                transform: translateY(30px) scale(0.96);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* ========================================
           左侧面板 - 品牌与信息区域
           ======================================== */
        .harmony-left-panel {
            background: linear-gradient(135deg, var(--harmony-primary) 0%, #0052CC 100%);
            padding: var(--harmony-space-xxxl);
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .harmony-left-panel::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 60%);
            pointer-events: none;
        }

        .harmony-left-panel::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -30%;
            width: 150%;
            height: 150%;
            background: radial-gradient(circle, rgba(0, 199, 190, 0.15) 0%, transparent 50%);
            pointer-events: none;
        }

        .harmony-brand {
            display: inline-flex;
            align-items: center;
            gap: var(--harmony-space-lg);
            margin-bottom: var(--harmony-space-xxl);
            position: relative;
            z-index: 1;
        }

        .harmony-brand-icon {
            width: 64px;
            height: 64px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: var(--harmony-radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .harmony-brand-text {
            font-size: 36px;
            font-weight: 700;
            color: white;
            letter-spacing: -0.5px;
        }

        .harmony-header-title {
            font-size: 28px;
            font-weight: 600;
            color: white;
            margin-bottom: var(--harmony-space-lg);
            position: relative;
            z-index: 1;
        }

        .harmony-header-subtitle {
            font-size: 16px;
            color: rgba(255, 255, 255, 0.85);
            line-height: 1.7;
            position: relative;
            z-index: 1;
        }

        .harmony-features {
            margin-top: var(--harmony-space-xxl);
            position: relative;
            z-index: 1;
        }

        .harmony-feature-item {
            display: flex;
            align-items: center;
            gap: var(--harmony-space-md);
            margin-bottom: var(--harmony-space-md);
            color: rgba(255, 255, 255, 0.9);
            font-size: 15px;
        }

        .harmony-feature-item i {
            width: 24px;
            height: 24px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            flex-shrink: 0;
        }

        /* ========================================
           右侧面板 - 表单区域
           ======================================== */
        .harmony-right-panel {
            padding: var(--harmony-space-xxxl);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        /* ========================================
           系统检查 - 列表式卡片
           ======================================== */
        .harmony-section {
            margin-bottom: var(--harmony-space-xxl);
        }

        .harmony-section:last-child {
            margin-bottom: 0;
        }

        .harmony-section-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--harmony-text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: var(--harmony-space-md);
            padding-left: var(--harmony-space-sm);
        }

        .harmony-checklist {
            background: var(--harmony-bg-tertiary);
            border-radius: var(--harmony-radius-lg);
            padding: var(--harmony-space-md);
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: var(--harmony-space-sm);
        }

        .harmony-check-item {
            display: flex;
            align-items: center;
            gap: var(--harmony-space-sm);
            padding: var(--harmony-space-sm) var(--harmony-space-md);
            border-radius: var(--harmony-radius-md);
            font-size: 14px;
            color: var(--harmony-text-secondary);
            transition: background var(--harmony-duration-fast) var(--harmony-easing);
        }

        .harmony-check-item:hover {
            background: var(--harmony-bg-secondary);
        }

        .harmony-check-icon {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            flex-shrink: 0;
        }

        .harmony-check-item.success .harmony-check-icon {
            background: rgba(52, 199, 89, 0.12);
            color: var(--harmony-success);
        }

        .harmony-check-item.error .harmony-check-icon {
            background: rgba(250, 44, 44, 0.08);
            color: var(--harmony-error);
        }

        .harmony-form-group {
            margin-bottom: var(--harmony-space-lg);
        }

        .harmony-form-group:last-child {
            margin-bottom: 0;
        }

        .harmony-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--harmony-space-md);
        }

        .harmony-label {
            display: block;
            font-size: 15px;
            font-weight: 500;
            color: var(--harmony-text-primary);
            margin-bottom: var(--harmony-space-sm);
            padding-left: var(--harmony-space-xs);
        }

        .harmony-label .optional {
            color: var(--harmony-text-tertiary);
            font-weight: 400;
            font-size: 14px;
        }

        .harmony-input-wrapper {
            position: relative;
        }

        .harmony-input-icon {
            position: absolute;
            left: var(--harmony-space-md);
            top: 50%;
            transform: translateY(-50%);
            color: var(--harmony-text-tertiary);
            font-size: 18px;
            transition: color var(--harmony-duration-fast) var(--harmony-easing);
        }

        .harmony-input {
            width: 100%;
            height: 48px;
            padding: 0 var(--harmony-space-md) 0 44px;
            font-size: 16px;
            color: var(--harmony-text-primary);
            background: var(--harmony-bg-tertiary);
            border: 1.5px solid transparent;
            border-radius: var(--harmony-radius-md);
            transition: all var(--harmony-duration-fast) var(--harmony-easing);
            -webkit-appearance: none;
            appearance: none;
        }

        .harmony-input:hover {
            background: var(--harmony-bg-secondary);
            border-color: var(--harmony-gray-5);
        }

        .harmony-input:focus {
            outline: none;
            background: var(--harmony-bg-secondary);
            border-color: var(--harmony-primary);
        }

        .harmony-input:focus+.harmony-input-icon,
        .harmony-input-wrapper:focus-within .harmony-input-icon {
            color: var(--harmony-primary);
        }

        .harmony-input::placeholder {
            color: var(--harmony-text-fourth);
        }

        /* ========================================
           提示信息 - 轻量设计
           ======================================== */
        .harmony-alert {
            padding: var(--harmony-space-md) var(--harmony-space-lg);
            border-radius: var(--harmony-radius-md);
            margin-bottom: var(--harmony-space-lg);
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: var(--harmony-space-md);
            animation: harmonySlideIn var(--harmony-duration-normal) var(--harmony-easing);
        }

        @keyframes harmonySlideIn {
            from {
                opacity: 0;
                transform: translateY(-8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .harmony-alert-error {
            background: rgba(250, 44, 44, 0.06);
            color: var(--harmony-error);
            border: 1px solid rgba(250, 44, 44, 0.12);
        }

        /* ========================================
           按钮 - 胶囊按钮设计
           ======================================== */
        .harmony-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: var(--harmony-space-sm);
            width: 100%;
            height: 48px;
            padding: 0 var(--harmony-space-xl);
            font-size: 16px;
            font-weight: 600;
            color: white;
            background: var(--harmony-primary);
            border: none;
            border-radius: var(--harmony-radius-xl);
            cursor: pointer;
            transition: all var(--harmony-duration-fast) var(--harmony-easing);
            text-decoration: none;
            -webkit-tap-highlight-color: transparent;
            position: relative;
            overflow: hidden;
        }

        .harmony-btn::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.1) 0%, transparent 50%);
            opacity: 0;
            transition: opacity var(--harmony-duration-fast);
        }

        .harmony-btn:hover {
            background: var(--harmony-primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 125, 255, 0.3);
        }

        .harmony-btn:hover::after {
            opacity: 1;
        }

        .harmony-btn:active {
            background: var(--harmony-primary-pressed);
            transform: translateY(0);
            box-shadow: none;
        }

        .harmony-btn i {
            font-size: 16px;
        }

        /* ========================================
           成功状态 - 庆祝动画
           ======================================== */
        .harmony-success {
            text-align: center;
            padding: var(--harmony-space-lg) 0;
        }

        .harmony-success-icon {
            width: 96px;
            height: 96px;
            margin: 0 auto var(--harmony-space-xl);
            background: linear-gradient(135deg, var(--harmony-success) 0%, var(--harmony-accent) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 44px;
            box-shadow: 0 12px 40px rgba(52, 199, 89, 0.35);
            animation: harmonySuccessPop var(--harmony-duration-slow) var(--harmony-easing-spring);
            position: relative;
        }

        .harmony-success-icon::before {
            content: '';
            position: absolute;
            inset: -4px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--harmony-success) 0%, var(--harmony-accent) 100%);
            opacity: 0.3;
            animation: harmonyPulse 2s ease-in-out infinite;
        }

        @keyframes harmonySuccessPop {
            0% {
                opacity: 0;
                transform: scale(0.4);
            }

            60% {
                transform: scale(1.1);
            }

            100% {
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes harmonyPulse {

            0%,
            100% {
                transform: scale(1);
                opacity: 0.3;
            }

            50% {
                transform: scale(1.1);
                opacity: 0.1;
            }
        }

        .harmony-success-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--harmony-text-primary);
            margin-bottom: var(--harmony-space-sm);
        }

        .harmony-success-text {
            font-size: 15px;
            color: var(--harmony-text-secondary);
            margin-bottom: var(--harmony-space-xxl);
        }

        /* ========================================
           页脚
           ======================================== */
        .harmony-footer {
            text-align: center;
            margin-top: var(--harmony-space-xxl);
            color: var(--harmony-text-tertiary);
            font-size: 13px;
        }

        /* ========================================
           响应式适配
           ======================================== */
        @media (max-width: 768px) {
            body {
                padding: var(--harmony-space-md);
                align-items: flex-start;
            }

            .harmony-container {
                max-width: 100%;
                grid-template-columns: 1fr;
                min-height: auto;
            }

            .harmony-left-panel {
                padding: var(--harmony-space-xl);
                text-align: center;
            }

            .harmony-brand {
                flex-direction: column;
                margin-bottom: var(--harmony-space-lg);
            }

            .harmony-brand-icon {
                width: 56px;
                height: 56px;
                font-size: 28px;
            }

            .harmony-brand-text {
                font-size: 32px;
            }

            .harmony-header-title {
                font-size: 24px;
            }

            .harmony-header-subtitle {
                font-size: 15px;
            }

            .harmony-features {
                display: none;
            }

            .harmony-right-panel {
                padding: var(--harmony-space-xl);
            }

            .harmony-checklist {
                grid-template-columns: 1fr;
            }

            .harmony-form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .harmony-input {
                font-size: 16px;
            }
        }

        @media (max-width: 360px) {
            .harmony-card-body {
                padding: var(--harmony-space-lg);
            }
        }

        /* 横屏优化 */
        @media (max-height: 600px) and (orientation: landscape) {
            body {
                align-items: flex-start;
                padding: var(--harmony-space-md);
            }

            .harmony-header {
                margin-bottom: var(--harmony-space-lg);
            }

            .harmony-brand-icon {
                width: 44px;
                height: 44px;
                font-size: 22px;
            }
        }

        /* ========================================
           暗色模式 - UI Dark
           ======================================== */
        @media (prefers-color-scheme: dark) {
            :root {
                --harmony-bg-primary: #0D0D0D;
                --harmony-bg-secondary: #1A1A1A;
                --harmony-bg-tertiary: #262626;

                --harmony-text-primary: #FFFFFF;
                --harmony-text-secondary: #B3B3B3;
                --harmony-text-tertiary: #808080;
                --harmony-text-fourth: #4D4D4D;

                --harmony-gray-5: #333333;
                --harmony-gray-6: #404040;

                --harmony-shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.2);
                --harmony-shadow-md: 0 4px 16px rgba(0, 0, 0, 0.3);
                --harmony-shadow-lg: 0 8px 32px rgba(0, 0, 0, 0.4);
            }

            body {
                background: linear-gradient(180deg,
                        #0D0D0D 0%,
                        #141414 50%,
                        #0D0D0D 100%);
            }

            .harmony-left-panel {
                background: linear-gradient(135deg, #0052CC 0%, #003A99 100%);
            }

            .harmony-input {
                background: var(--harmony-bg-tertiary);
            }

            .harmony-input:hover,
            .harmony-input:focus {
                background: var(--harmony-bg-secondary);
            }

            .harmony-checklist {
                background: var(--harmony-bg-tertiary);
            }

            .harmony-check-item:hover {
                background: rgba(255, 255, 255, 0.04);
            }
        }

        .install-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            backdrop-filter: blur(4px);
        }

        .install-overlay.active {
            display: flex;
        }

        .install-overlay .spinner {
            width: 48px;
            height: 48px;
            border: 3px solid rgba(255, 255, 255, 0.2);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            margin-bottom: 20px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .install-overlay .text {
            color: #fff;
            font-size: 16px;
            font-weight: 500;
        }

        .install-overlay .sub {
            color: rgba(255, 255, 255, 0.7);
            font-size: 13px;
            margin-top: 6px;
        }

        .harmony-security {
            text-align: center;
            padding: var(--harmony-space-md) 0;
        }

        .harmony-security-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto var(--harmony-space-lg);
            background: linear-gradient(135deg, var(--harmony-warning) 0%, #FF6B00 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 36px;
            box-shadow: 0 8px 24px rgba(255, 149, 0, 0.3);
            animation: harmonySuccessPop var(--harmony-duration-slow) var(--harmony-easing-spring);
        }

        .harmony-security-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--harmony-text-primary);
            margin-bottom: var(--harmony-space-sm);
        }

        .harmony-security-desc {
            font-size: 14px;
            color: var(--harmony-text-secondary);
            margin-bottom: var(--harmony-space-xl);
        }

        .harmony-dir-list {
            display: flex;
            flex-direction: column;
            gap: var(--harmony-space-sm);
            margin-bottom: var(--harmony-space-xl);
            text-align: left;
        }

        .harmony-dir-item {
            display: flex;
            align-items: center;
            gap: var(--harmony-space-md);
            padding: var(--harmony-space-md);
            background: var(--harmony-bg-tertiary);
            border-radius: var(--harmony-radius-md);
            font-size: 14px;
        }

        .harmony-dir-item i {
            color: var(--harmony-warning);
            font-size: 16px;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }

        .harmony-dir-item strong {
            display: block;
            font-size: 13px;
            color: var(--harmony-text-primary);
            font-family: 'SF Mono', 'Fira Code', 'Consolas', monospace;
        }

        .harmony-dir-item small {
            display: block;
            font-size: 12px;
            color: var(--harmony-text-tertiary);
            margin-top: 1px;
        }

        .harmony-config-section {
            margin-bottom: var(--harmony-space-xl);
            text-align: left;
        }

        .harmony-code-block {
            background: var(--harmony-bg-tertiary);
            border-radius: var(--harmony-radius-md);
            padding: var(--harmony-space-md) var(--harmony-space-lg);
            font-family: 'SF Mono', 'Fira Code', 'Consolas', monospace;
            font-size: 13px;
            color: var(--harmony-text-primary);
            text-align: left;
            overflow-x: auto;
            border: 1px solid var(--harmony-gray-5);
            line-height: 1.6;
        }

        .harmony-config-note {
            font-size: 12px;
            color: var(--harmony-text-tertiary);
            margin-top: var(--harmony-space-sm);
        }

        .harmony-btn-outline {
            background: transparent;
            color: var(--harmony-primary);
            border: 1.5px solid var(--harmony-primary);
            margin-bottom: var(--harmony-space-lg);
        }

        .harmony-btn-outline:hover {
            background: var(--harmony-primary-light);
            color: var(--harmony-primary);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 125, 255, 0.15);
        }

        .harmony-btn-outline:active {
            background: var(--harmony-primary-light);
            color: var(--harmony-primary-pressed);
            transform: translateY(0);
            box-shadow: none;
        }

        .harmony-btn-outline:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .harmony-check-result-item {
            display: flex;
            align-items: center;
            gap: var(--harmony-space-sm);
            padding: var(--harmony-space-sm) var(--harmony-space-md);
            border-radius: var(--harmony-radius-md);
            font-size: 13px;
            margin-bottom: var(--harmony-space-sm);
            text-align: left;
        }

        .harmony-check-result-item.safe {
            background: rgba(52, 199, 89, 0.08);
            color: var(--harmony-success);
        }

        .harmony-check-result-item.exposed {
            background: rgba(250, 44, 44, 0.06);
            color: var(--harmony-error);
        }

        .setup-steps {
            display: flex;
            gap: 0;
            margin-bottom: 24px;
            border-radius: var(--harmony-radius-xl);
            background: var(--harmony-bg-tertiary);
            padding: 4px;
        }

        .setup-step {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 12px;
            border-radius: var(--harmony-radius-lg);
            font-size: 13px;
            color: var(--harmony-text-tertiary);
            transition: all 0.2s;
        }

        .setup-step.active {
            background: var(--harmony-white);
            color: var(--harmony-primary);
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .setup-step-num {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            background: var(--harmony-gray-5);
            color: var(--harmony-text-tertiary);
            flex-shrink: 0;
        }

        .setup-step.active .setup-step-num {
            background: var(--harmony-primary);
            color: white;
        }
    </style>
</head>

<body>
    <div class="install-overlay" id="installOverlay">
        <div class="spinner"></div>
        <div class="text">正在安装中...</div>
        <div class="sub">正在进行初始化，请稍候</div>
    </div>
    <div class="harmony-container">
        <!-- 左侧面板 -->
        <div class="harmony-left-panel">
            <div class="harmony-brand">
                <div class="harmony-brand-icon">
                    <i class="fas fa-cloud"></i>
                </div>
                <span class="harmony-brand-text">柚舟Cloud</span>
            </div>
            <h1 class="harmony-header-title">欢迎使用</h1>
            <p class="harmony-header-subtitle">完成以下设置，开始您的私有云存储之旅</p>

            <div class="harmony-features">
                <div class="harmony-feature-item">
                    <i class="fas fa-check"></i>
                    <span>安全加密存储</span>
                </div>
                <div class="harmony-feature-item">
                    <i class="fas fa-check"></i>
                    <span>多端同步访问</span>
                </div>
                <div class="harmony-feature-item">
                    <i class="fas fa-check"></i>
                    <span>简单易用</span>
                </div>
            </div>
        </div>

        <!-- 右侧面板 -->
        <div class="harmony-right-panel">
            <?php if (!$showSecurityPage): ?>
                <!-- 步骤指示器 -->
                <div class="setup-steps">
                    <div class="setup-step active" data-step="1">
                        <div class="setup-step-num">1</div>
                        <span>环境检测</span>
                    </div>
                    <div class="setup-step" data-step="2">
                        <div class="setup-step-num">2</div>
                        <span>配置安装</span>
                    </div>
                    <div class="setup-step" data-step="3">
                        <div class="setup-step-num">3</div>
                        <span>安全加固</span>
                    </div>
                </div>

                <!-- Step 1: 环境检查 -->
                <div class="setup-panel" id="step1">
                    <div class="harmony-section">
                        <div class="harmony-section-title">环境检测</div>
                        <div class="harmony-checklist">
                            <?php
                            $checks = [
                                ['PHP >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>=')],
                                ['PDO SQLite', extension_loaded('pdo_sqlite')],
                                ['SQLite3', extension_loaded('sqlite3')],
                                ['PDO MySQL', extension_loaded('pdo_mysql')],
                                ['PDO PostgreSQL', extension_loaded('pdo_pgsql')],
                                ['文件上传', ini_get('file_uploads')],
                                ['JSON', function_exists('json_encode')],
                                ['ZipArchive', class_exists('ZipArchive')],
                                ['/storage/ 可写', is_writable(STORAGE_PATH)],
                                ['/uploads/ 可写', is_writable(UPLOAD_PATH)],
                            ];
                            foreach ($checks as $check): ?>
                                <div class="harmony-check-item <?php echo $check[1] ? 'success' : 'error'; ?>">
                                    <span class="harmony-check-icon">
                                        <i class="fas <?php echo $check[1] ? 'fa-check' : 'fa-times'; ?>"></i>
                                    </span>
                                    <span><?php echo $check[0]; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button type="button" class="harmony-btn" onclick="nextStep()" style="margin-top:var(--harmony-space-lg);">
                        <i class="fas fa-arrow-right"></i>
                        环境通过，下一步
                    </button>
                </div>
            <?php endif; ?>

            <!-- 错误提示 -->
            <?php if ($error): ?>
                <div class="harmony-alert harmony-alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo Security::escape($error); ?></span>
                </div>
            <?php endif; ?>

            <!-- 安全配置提醒 -->
            <?php if ($showSecurityPage): ?>
                <div class="harmony-security">
                    <div class="harmony-security-icon">
                        <i class="fas fa-shield-halved"></i>
                    </div>
                    <h2 class="harmony-security-title">安全配置</h2>
                    <p class="harmony-security-desc">请配置 Web 服务器，禁止直接访问以下目录</p>

                    <div class="harmony-dir-list">
                        <div class="harmony-dir-item">
                            <i class="fas fa-database"></i>
                            <div>
                                <strong>/storage/</strong>
                                <small>数据库/配置/用户文件</small>
                            </div>
                        </div>
                        <div class="harmony-dir-item">
                            <i class="fas fa-folder"></i>
                            <div>
                                <strong>/uploads/</strong>
                                <small>上传临时目录</small>
                            </div>
                        </div>
                    </div>

                    <div class="harmony-config-section">
                        <div class="harmony-section-title" style="text-align:left;">Nginx 配置参考</div>
                        <div class="harmony-code-block"><code>location ^~ /storage/ {
                                deny all;
                                }</code></div>
                    </div>

                    <button id="securityCheckBtn" class="harmony-btn harmony-btn-outline" onclick="runSecurityCheck()">
                        <i class="fas fa-magnifying-glass"></i>
                        检测访问保护
                    </button>

                    <div id="securityCheckResults"></div>

                    <form method="POST" style="margin-top:var(--harmony-space-lg);">
                        <input type="hidden" name="action" value="finish_security">
                        <button type="submit" class="harmony-btn">
                            <i class="fas fa-arrow-right"></i>
                            已完成配置，前往登录
                        </button>
                    </form>
                </div>
            <?php elseif ($success): ?>
                <div class="harmony-success">
                    <div class="harmony-success-icon">
                        <i class="fas fa-check"></i>
                    </div>
                    <h2 class="harmony-success-title">安装成功</h2>
                    <p class="harmony-success-text">您的 柚舟Cloud 已准备就绪</p>
                    <a href="index.php?page=login" class="harmony-btn">
                        <i class="fas fa-sign-in-alt"></i>
                        前往登录
                    </a>
                </div>
            <?php else: ?>
                <!-- Step 2: 安装表单 -->
                <div class="setup-panel" id="step2" style="display:none">
                    <form method="POST" autocomplete="off">
                        <input type="hidden" name="install_token" value="<?php echo Security::escape($installToken); ?>">

                        <div class="harmony-section">
                            <div class="harmony-section-title">数据库配置</div>

                            <div class="harmony-form-group">
                                <label class="harmony-label" for="db_type">数据库类型</label>
                                <div class="harmony-input-wrapper">
                                    <select
                                        id="db_type"
                                        name="db_type"
                                        class="harmony-input"
                                        style="cursor: pointer;"
                                        onchange="toggleDbConfig()">
                                        <option value="sqlite">SQLite (推荐)</option>
                                        <option value="mysql">MySQL</option>
                                        <option value="pgsql">PostgreSQL</option>
                                    </select>
                                    <i class="fas fa-database harmony-input-icon"></i>
                                </div>
                            </div>

                            <div id="dbConfigFields" style="display: none;">
                                <div class="harmony-form-row">
                                    <div class="harmony-form-group">
                                        <label class="harmony-label" for="db_host">主机地址</label>
                                        <div class="harmony-input-wrapper">
                                            <input
                                                type="text"
                                                id="db_host"
                                                name="db_host"
                                                class="harmony-input"
                                                value="127.0.0.1"
                                                placeholder="数据库主机">
                                            <i class="fas fa-server harmony-input-icon"></i>
                                        </div>
                                    </div>
                                    <div class="harmony-form-group">
                                        <label class="harmony-label" for="db_port">端口</label>
                                        <div class="harmony-input-wrapper">
                                            <input
                                                type="number"
                                                id="db_port"
                                                name="db_port"
                                                class="harmony-input"
                                                value="3306"
                                                placeholder="端口">
                                            <i class="fas fa-network-wired harmony-input-icon"></i>
                                        </div>
                                    </div>
                                </div>

                                <div class="harmony-form-group">
                                    <label class="harmony-label" for="db_database">数据库名</label>
                                    <div class="harmony-input-wrapper">
                                        <input
                                            type="text"
                                            id="db_database"
                                            name="db_database"
                                            class="harmony-input"
                                            value="pancloud"
                                            placeholder="数据库名称"
                                            required>
                                        <i class="fas fa-database harmony-input-icon"></i>
                                    </div>
                                </div>

                                <div class="harmony-form-row">
                                    <div class="harmony-form-group">
                                        <label class="harmony-label" for="db_username">用户名</label>
                                        <div class="harmony-input-wrapper">
                                            <input
                                                type="text"
                                                id="db_username"
                                                name="db_username"
                                                class="harmony-input"
                                                value="root"
                                                placeholder="数据库用户名"
                                                required>
                                            <i class="fas fa-user harmony-input-icon"></i>
                                        </div>
                                    </div>
                                    <div class="harmony-form-group">
                                        <label class="harmony-label" for="db_password">密码</label>
                                        <div class="harmony-input-wrapper">
                                            <input
                                                type="password"
                                                id="db_password"
                                                name="db_password"
                                                class="harmony-input"
                                                placeholder="数据库密码">
                                            <i class="fas fa-lock harmony-input-icon"></i>
                                        </div>
                                    </div>
                                </div>

                                <div class="harmony-form-group" id="charsetField">
                                    <label class="harmony-label" for="db_charset">字符集</label>
                                    <div class="harmony-input-wrapper">
                                        <input
                                            type="text"
                                            id="db_charset"
                                            name="db_charset"
                                            class="harmony-input"
                                            value="utf8mb4"
                                            placeholder="字符集">
                                        <i class="fas fa-font harmony-input-icon"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="harmony-section">
                            <div class="harmony-section-title">创建管理员账户</div>

                            <div class="harmony-form-group">
                                <label class="harmony-label" for="username">用户名</label>
                                <div class="harmony-input-wrapper">
                                    <input
                                        type="text"
                                        id="username"
                                        name="username"
                                        class="harmony-input"
                                        required
                                        minlength="3"
                                        maxlength="32"
                                        value="<?php echo Security::escape($username ?? ''); ?>"
                                        placeholder="3-32 位字符">
                                    <i class="fas fa-user harmony-input-icon"></i>
                                </div>
                            </div>

                            <div class="harmony-form-row">
                                <div class="harmony-form-group">
                                    <label class="harmony-label" for="password">密码</label>
                                    <div class="harmony-input-wrapper">
                                        <input
                                            type="password"
                                            id="password"
                                            name="password"
                                            class="harmony-input"
                                            required
                                            minlength="8"
                                            placeholder="至少 8 位">
                                        <i class="fas fa-lock harmony-input-icon"></i>
                                    </div>
                                </div>
                                <div class="harmony-form-group">
                                    <label class="harmony-label" for="confirm_password">确认密码</label>
                                    <div class="harmony-input-wrapper">
                                        <input
                                            type="password"
                                            id="confirm_password"
                                            name="confirm_password"
                                            class="harmony-input"
                                            required
                                            minlength="8"
                                            placeholder="再次输入">
                                        <i class="fas fa-lock harmony-input-icon"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="harmony-form-group">
                                <label class="harmony-label" for="email">
                                    邮箱 <span class="optional">选填</span>
                                </label>
                                <div class="harmony-input-wrapper">
                                    <input
                                        type="email"
                                        id="email"
                                        name="email"
                                        class="harmony-input"
                                        value="<?php echo Security::escape($email ?? ''); ?>"
                                        placeholder="用于找回密码">
                                    <i class="fas fa-envelope harmony-input-icon"></i>
                                </div>
                            </div>
                        </div>

                        <div class="harmony-form-group" style="margin-top:var(--harmony-space-lg);">
                            <label class="harmony-checkbox-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;">
                                <input type="checkbox" name="telemetry" value="1" checked style="width:16px;height:16px;cursor:pointer;">
                                <span>
                                    启用匿名使用统计以帮助改进产品
                                    <a href="https://yoliarkpanelapi.hiyanyi.top/privacy" target="_blank" style="font-size:12px;color:var(--harmony-text-tertiary);text-decoration:underline;">隐私政策</a>
                                </span>
                            </label>
                            <p style="font-size:12px;color:var(--harmony-text-tertiary);margin-top:4px;padding-left:24px;">
                                仅收集运行环境信息用于兼容性优化，不会收集文件或个人身份信息。
                                如果取消勾选，密码找回功能需要手动配置 SMTP。
                            </p>
                        </div>

                        <button type="submit" class="harmony-btn">
                            <i class="fas fa-rocket"></i>
                            开始安装
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
        var setupStep = 1;

        function goToStep(n) {
            setupStep = n;
            document.querySelectorAll('.setup-panel').forEach(function(el) {
                el.style.display = 'none';
            });
            var panel = document.getElementById('step' + n);
            if (panel) panel.style.display = 'block';
            document.querySelectorAll('.setup-step').forEach(function(el) {
                el.classList.remove('active');
            });
            var step = document.querySelector('.setup-step[data-step="' + n + '"]');
            if (step) step.classList.add('active');
        }

        function nextStep() {
            goToStep(setupStep + 1);
        }
        document.addEventListener('DOMContentLoaded', function() {
            goToStep(1);
        });

        function toggleDbConfig() {
            var dbTypeEl = document.getElementById('db_type');
            if (!dbTypeEl) return;
            var dbType = dbTypeEl.value;
            var configFields = document.getElementById('dbConfigFields');
            var portField = document.getElementById('db_port');
            var usernameField = document.getElementById('db_username');
            var charsetField = document.getElementById('charsetField');

            if (dbType === 'sqlite') {
                configFields.style.display = 'none';
                portField.required = false;
                usernameField.required = false;
            } else {
                configFields.style.display = 'block';
                usernameField.required = true;

                if (dbType === 'mysql') {
                    portField.value = '3306';
                    charsetField.querySelector('input').value = 'utf8mb4';
                } else if (dbType === 'pgsql') {
                    portField.value = '5432';
                    usernameField.value = 'postgres';
                    charsetField.style.display = 'none';
                } else {
                    charsetField.style.display = 'block';
                }
            }
        }

        (function() {
            var form = document.querySelector('form[method="POST"]');
            if (form && !form.querySelector('input[name="action"]')) {
                form.addEventListener('submit', function() {
                    document.getElementById('installOverlay').classList.add('active');
                });
            }
            toggleDbConfig();
        })();

        function runSecurityCheck() {
            var btn = document.getElementById('securityCheckBtn');
            var results = document.getElementById('securityCheckResults');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 检测中...';
            results.innerHTML = '';

            var checks = <?php echo json_encode($_SESSION['install_check_paths'] ?? []); ?>;
            var completed = 0;
            var total = checks.length;

            checks.forEach(function(check) {
                fetch(check.url, {
                        method: 'GET',
                        cache: 'no-cache'
                    })
                    .then(function(response) {
                        if (response.ok) {
                            return response.text();
                        }
                        throw new Error('blocked');
                    })
                    .then(function(text) {
                        if (text.indexOf('security_check') !== -1) {
                            addResult(check.label + ' ' + check.desc, false);
                        } else {
                            addResult(check.label + ' ' + check.desc, true);
                        }
                    })
                    .catch(function() {
                        addResult(check.label + ' ' + check.desc, true);
                    })
                    .finally(function() {
                        completed++;
                        if (completed === total) {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-magnifying-glass"></i> 重新检测';
                        }
                    });
            });

            function addResult(label, isSafe) {
                var div = document.createElement('div');
                div.className = 'harmony-check-result-item ' + (isSafe ? 'safe' : 'exposed');
                div.innerHTML = '<i class="fas ' + (isSafe ? 'fa-check-circle' : 'fa-exclamation-circle') + '"></i> ' +
                    label + (isSafe ? ' — 已保护' : ' — 可直接访问！');
                results.appendChild(div);
            }
        }
    </script>
</body>

</html>