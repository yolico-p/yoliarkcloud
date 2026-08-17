<?php
require_once __DIR__ . '/bootstrap/app.php';

use App\Core\Security;
use App\Core\Config;
use App\Core\Database;
use App\Core\SqlitePerformanceTuner;
use App\Services\AuthService;
use App\Services\ShareService;

Security::init();
define('SECURITY_INITIALIZED', true);

$config = Config::getInstance();

if (!$config->isInstalled()) {
    Security::redirect('install.php');
}

// SQLite 性能调优：在 Database 初始化后立即应用 PRAGMA 优化 + 注册 shutdown 维护
// 非 SQLite 模式自动跳过，无副作用
SqlitePerformanceTuner::getInstance()->optimize();
if (!defined('SQLITE_TUNER_REGISTERED')) {
    SqlitePerformanceTuner::registerShutdownMaintenance();
    define('SQLITE_TUNER_REGISTERED', true);
}

// 回收站清理：每小时最多执行一次，不阻塞页面
$lastCleanupFile = DATA_PATH . DIRECTORY_SEPARATOR . '.trash_cleanup';
$lastCleanup = file_exists($lastCleanupFile) ? (int)file_get_contents($lastCleanupFile) : 0;
if (time() - $lastCleanup > 3600) {
    $trashService = new \App\Services\TrashService();
    $trashService->cleanExpired();
    @file_put_contents($lastCleanupFile, (string)time());
}

if (isset($_GET['action'])) {
    require __DIR__ . '/api.php';
    exit;
}

// 页面路由
$allowedPages = ['files', 'login', 'share', 'recent', 'favorites', 'shares', 'inbox', 'trash', 'logs', 'ai', 'settings'];
$page = $_GET['page'] ?? 'files';

if (!in_array($page, $allowedPages)) {
    $page = 'files';
}

$token = $_GET['token'] ?? '';

Security::botChallenge();

$auth = new AuthService();
$isLoggedIn = $auth->isLoggedIn();
$user = $isLoggedIn ? $auth->getUser() : null;

if ($isLoggedIn) {
    $csrfToken = Security::generateCSRFToken();
} else {
    $csrfToken = bin2hex(random_bytes(32));
}

// 登录状态与 CSRF Token 已确定，立即释放 Session 文件锁，避免阻塞并发请求
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// 分享页数据
$shareData = null;
if ($page === 'share' && !empty($token)) {
    $shareService = new ShareService();
    $shareInfo = $shareService->getShareByToken($token);
    if ($shareInfo) {
        $shareInfoFull = $shareService->getShareInfo($token);
        if ($shareInfoFull) {
            $file = $shareInfoFull['file'];
            $shareData = [
                'token' => $token,
                'filename' => $file['filename'],
                'filesize_formatted' => Security::formatSize($file['filesize']),
                'filesize' => $file['filesize'],
                'file_type' => $file['file_type'] ?? '',
                'file_id' => $file['id'] ?? 0,
                'mime_type' => $file['mime_type'] ?? '',
                'has_password' => $shareInfoFull['has_password'],
            ];
        }
    }
}

// 收件箱页面数据
$inboxData = null;
if ($page === 'inbox' && !empty($token)) {
    $inboxService = new \App\Services\InboxService();
    $verifyResult = $inboxService->verifyToken($token);
    if ($verifyResult['valid']) {
        $inboxData = [
            'token' => $token,
            'app_name' => $verifyResult['app_name'],
        ];
    }
}

if (!$isLoggedIn && !in_array($page, ['login', 'share', 'inbox'])) {
    Security::redirect('index.php?page=login');
}

// 页面构建哈希
$assetFiles = array_merge(
    glob(__DIR__ . '/assets/css/*.css') ?: [],
    glob(__DIR__ . '/assets/js/*.js') ?: []
);
$assetMtimes = array_map('filemtime', $assetFiles);
$pageBuildHash = hash('sha256', __FILE__ . implode(',', $assetMtimes));

// --- 视图渲染 ---
require __DIR__ . '/views/layouts/head.php';

if ($page === 'login') {
    require __DIR__ . '/views/pages/login.php';
} elseif ($page === 'share') {
    require __DIR__ . '/views/pages/share.php';
} elseif ($page === 'inbox') {
    require __DIR__ . '/views/pages/inbox.php';
} else {
    require __DIR__ . '/views/pages/app.php';
}

// 通用脚本（所有页面都需要加载 JS 文件和 APP_CONFIG）
require __DIR__ . '/views/layouts/scripts.php';

// 页面专属脚本（按需加载，避免无关页面加载多余的 JS）
if ($page === 'login') {
    require __DIR__ . '/views/pages/_login_script.php';
} elseif ($page !== 'share' && $page !== 'inbox') {
    // share / inbox 页面的内联脚本包含在自身中
    require __DIR__ . '/views/pages/_app_script.php';
}

require __DIR__ . '/views/layouts/foot.php';

// Worker 守护：按需拉起后台 Worker 进程（页面输出完成后调用，不阻塞用户请求）
\App\Core\WorkerGuard::checkAndSpawnIfNeeded();
