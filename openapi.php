<?php
/**
 * 开放 API 入口
 * 通过 Bearer Token 认证，无需 Session/CSRF
 * 用法: openapi.php?action=list_files&parent_id=0
 * 认证: Header Authorization: Bearer <token>
 */

require_once __DIR__ . '/bootstrap/app.php';

use App\Core\Security;
use App\Core\Config;

Security::init(false); // 不启用压缩，API 模式手动控制输出

// CORS 头 - API 模式允许跨域
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Version');
header('Access-Control-Max-Age: 86400');

// OPTIONS 预检请求直接通过
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$config = Config::getInstance();

if (!$config->isInstalled()) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '系统未安装']);
    exit;
}

// ── Token 认证 ──
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = '';

if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
    $token = trim($matches[1]);
}

// 也支持 query 参数传递 token（方便 curl 测试）
if (empty($token)) {
    $token = $_GET['token'] ?? '';
}

if (empty($token)) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '缺少认证 Token', 'code' => 401]);
    exit;
}

if (!\App\Controllers\System\ApiController::verifyApiToken($token)) {
    // 记录失败尝试：走 AdaptiveRateLimiter 数据库令牌桶（统一限流基础设施）
    $ip = Security::getClientIP();
    \App\Core\AdaptiveRateLimiter::getInstance()->adaptiveCheck(
        'openapi_bad_token_' . $ip,
        1,
        0,
        'openapi_bad_token'
    );

    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '无效的 API Token', 'code' => 401]);
    exit;
}

// ── 速率限制（基于 AdaptiveRateLimiter 数据库令牌桶，避免文件 flock IO 瓶颈） ──
$ip = Security::getClientIP();
$rateLimit = (int)$config->get('api_rate_limit', 60);
$rateWindow = (int)$config->get('api_rate_window', 60);
$limitKey = 'openapi_' . $ip;

// 默认桶：60 token / 1 token-per-sec，与原固定窗口语义一致；
// 自定义 $rateLimit 时按比例折算 cost（保证 $rateLimit 次请求在 $rateWindow 秒内可通过）
$cost = max(0.01, $rateWindow > 0 ? (60.0 / $rateLimit) : 1.0);
$rlResult = \App\Core\AdaptiveRateLimiter::getInstance()->adaptiveCheck($limitKey, $cost, 0, 'openapi');
if (empty($rlResult['allowed'])) {
    $retry = (int)($rlResult['retry_after'] ?? $rateWindow);
    http_response_code(429);
    header('Content-Type: application/json; charset=utf-8');
    header('Retry-After: ' . $retry);
    echo json_encode(['error' => '请求过于频繁', 'code' => 429, 'retry_after' => $retry]);
    exit;
}

// ── 模拟登录状态，让现有 Controller 可以正常工作 ──
if (session_status() === PHP_SESSION_NONE) {
    session_name('PANCLOUD_SID');
    session_start();
}

// 单用户系统，取管理员用户 ID
$db = \App\Core\Database::getInstance();
$admin = $db->fetch("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
if (!$admin) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '系统配置错误：未找到管理员账户']);
    exit;
}

$_SESSION['user_id'] = $admin['id'];

// 生成 CSRF token（某些操作需要）
if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}

// ── 路由映射（开放 API 支持的操作） ──
$openApiRoutes = [
    // 文件操作
    'list_files'       => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'listFiles'],
    'file_info'        => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'fileInfo'],
    'search'           => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'search'],
    'breadcrumb'       => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'breadcrumb'],
    'storage_info'     => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'storageInfo'],
    'list_folders'     => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'listAllFolders'],
    'recent_access'    => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'recentAccess'],

    // 上传
    'upload'           => ['controller' => 'App\Controllers\File\UploadController', 'method' => 'upload'],
    'upload_chunk'     => ['controller' => 'App\Controllers\File\UploadController', 'method' => 'uploadChunk'],
    'cancel_upload'    => ['controller' => 'App\Controllers\File\UploadController', 'method' => 'cancelUpload'],
    'get_uploaded_chunks' => ['controller' => 'App\Controllers\File\UploadController', 'method' => 'getUploadedChunks'],

    // 文件操作
    'create_folder'    => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'createFolder'],
    'delete'           => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'delete'],
    'batch_delete'     => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'batchDelete'],
    'rename'           => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'rename'],
    'move'             => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'move'],
    'copy'             => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'copy'],
    'toggle_favorite'  => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'toggleFavorite'],
    'update_description' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'updateDescription'],
    'update_tags'      => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'updateTags'],

    // 下载
    'download'         => ['controller' => 'App\Controllers\File\DownloadController', 'method' => 'download'],
    'thumbnail'        => ['controller' => 'App\Controllers\File\DownloadController', 'method' => 'thumbnail'],

    // 分享
    'create_share'     => ['controller' => 'App\Controllers\Share\ShareManageController', 'method' => 'create'],
    'list_shares'      => ['controller' => 'App\Controllers\Share\ShareManageController', 'method' => 'list'],
    'delete_share'     => ['controller' => 'App\Controllers\Share\ShareManageController', 'method' => 'delete'],
    'toggle_share'     => ['controller' => 'App\Controllers\Share\ShareManageController', 'method' => 'toggle'],

    // 回收站
    'list_trash'       => ['controller' => 'App\Controllers\Trash\TrashController', 'method' => 'list'],
    'restore'          => ['controller' => 'App\Controllers\Trash\TrashController', 'method' => 'restore'],
    'permanent_delete' => ['controller' => 'App\Controllers\Trash\TrashController', 'method' => 'permanentDelete'],
    'empty_trash'      => ['controller' => 'App\Controllers\Trash\TrashController', 'method' => 'emptyTrash'],
];

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if (empty($action)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => '缺少 action 参数',
        'code' => 400,
        'available_actions' => array_keys($openApiRoutes),
    ]);
    exit;
}

if (!isset($openApiRoutes[$action])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => '不支持的操作: ' . $action,
        'code' => 400,
        'available_actions' => array_keys($openApiRoutes),
    ]);
    exit;
}

// ── CSRF 绕过：API 模式使用 Token 认证，不需要 CSRF ──
// 对于 POST/PUT/DELETE 请求，解析 JSON body
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $rawInput = file_get_contents('php://input');
        if (!empty($rawInput)) {
            $jsonData = json_decode($rawInput, true);
            $GLOBALS['_PANCLOUD_JSON_BODY'] = $jsonData ?: [];
        }
    }
    // 注入 CSRF token 使验证通过
    if (isset($GLOBALS['_PANCLOUD_JSON_BODY']) && is_array($GLOBALS['_PANCLOUD_JSON_BODY'])) {
        $GLOBALS['_PANCLOUD_JSON_BODY']['_csrf_token'] = $_SESSION['_csrf_token'];
    }
    $_POST['_csrf_token'] = $_SESSION['_csrf_token'];
}

$route = $openApiRoutes[$action];
$controllerClass = $route['controller'];
$method = $route['method'];

try {
    $controller = new $controllerClass();
    $controller->$method();
} catch (\Throwable $e) {
    error_log("[OPENAPI ERROR] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => '服务器内部错误',
        'code' => 500,
    ]);
}
