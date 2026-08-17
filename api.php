<?php

require_once __DIR__ . '/bootstrap/app.php';

use App\Core\Security;
use App\Core\Config;
use App\Core\SqlitePerformanceTuner;
use App\Core\Request;
use App\Core\Middleware;
use App\Core\AsyncLogger;
use App\Services\AuthService;

// 当 api.php 被 index.php 通过 require 调用时，Security::init() 已在 index.php 中执行
// 直接访问 api.php 时仍需初始化
if (!defined('SECURITY_INITIALIZED')) {
    Security::init();
    define('SECURITY_INITIALIZED', true);
}

$config = Config::getInstance();

if (!$config->isInstalled()) {
    Security::jsonOutput(['error' => '系统未安装，请先完成安装'], 503);
}

// SQLite 性能调优：optimize() 内部有幂等检查，重复调用安全
SqlitePerformanceTuner::getInstance()->optimize();
if (!defined('SQLITE_TUNER_REGISTERED')) {
    SqlitePerformanceTuner::registerShutdownMaintenance();
    define('SQLITE_TUNER_REGISTERED', true);
}

// 统一通过 Request 对象访问输入
$request = Request::current();
$action = $request->query('action') ?? $request->post('action') ?? '';

if (empty($action)) {
    Security::jsonOutput(['error' => '缺少 action 参数'], 400);
}

// 维护模式检查：更新期间拦截非必要 API 请求，避免文件竞态和 FPM 压力
$maintenanceFile = DATA_PATH . DIRECTORY_SEPARATOR . '.maintenance';
if (file_exists($maintenanceFile)) {
    $maintenanceAllowedActions = ['get_update_status', 'clear_update_failed'];
    if (!in_array($action, $maintenanceAllowedActions, true)) {
        http_response_code(503);
        Security::jsonOutput([
            'error' => '系统维护中',
            'maintenance' => true,
            'message' => '系统正在更新，请稍后再试',
        ]);
    }
}

$publicActions = [
    'login', 'share_info', 'share_download', 'share_direct', 'record_share_visit', 'license',
    'forgot_password', 'verify_reset',
    'inbox_upload', 'inbox_verify',
    'get_ads',
];

// ── 中间件链：非公开 action 必须通过鉴权 + CSRF ──
// 鉴权走 AuthService::isLoggedIn() 完整链（指纹/过期/会话固定防护）
// CSRF 走 Middleware::csrf() 统一入口
if (!in_array($action, $publicActions, true)) {
    Middleware::auth();
    Middleware::csrf($request);
}

// 许可协议内容（公开接口，无需登录）
if ($action === 'license') {
    $licenseFile = ROOT_PATH . DIRECTORY_SEPARATOR . 'LICENSE';
    if (file_exists($licenseFile)) {
        $content = file_get_contents($licenseFile);
        Security::jsonOutput(['success' => true, 'content' => $content]);
    }
    Security::jsonOutput(['success' => false, 'message' => '许可协议文件不存在'], 404);
}

$routeMap = [
    'login' => ['controller' => 'App\Controllers\Auth\LoginController', 'method' => 'login'],
    'logout' => ['controller' => 'App\Controllers\Auth\LoginController', 'method' => 'logout'],
    'change_password' => ['controller' => 'App\Controllers\Auth\ProfileController', 'method' => 'changePassword'],
    'update_profile' => ['controller' => 'App\Controllers\Auth\ProfileController', 'method' => 'updateProfile'],

    'list_files' => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'listFiles'],
    'get_favorites' => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'getFavorites'],
    'search' => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'search'],
    'file_info' => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'fileInfo'],
    'breadcrumb' => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'breadcrumb'],
    'storage_info' => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'storageInfo'],
    'file_stats' => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'fileStats'],
    'list_folders' => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'listAllFolders'],
    'list_all_folders' => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'listAllFolders'],
    'recent_access' => ['controller' => 'App\Controllers\File\FileListController', 'method' => 'recentAccess'],

    'upload' => ['controller' => 'App\Controllers\File\UploadController', 'method' => 'upload'],
    'upload_chunk' => ['controller' => 'App\Controllers\File\UploadController', 'method' => 'uploadChunk'],
    'cancel_upload' => ['controller' => 'App\Controllers\File\UploadController', 'method' => 'cancelUpload'],
    'resolve_upload_conflict' => ['controller' => 'App\Controllers\File\UploadController', 'method' => 'resolveUploadConflict'],
    'get_uploaded_chunks' => ['controller' => 'App\Controllers\File\UploadController', 'method' => 'getUploadedChunks'],
    'cleanup_expired_uploads' => ['controller' => 'App\Controllers\File\UploadController', 'method' => 'cleanupExpiredUploadTasks'],

    'create_folder' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'createFolder'],
    'delete' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'delete'],
    'batch_delete' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'batchDelete'],
    'rename' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'rename'],
    'move' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'move'],
    'copy' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'copy'],
    'toggle_favorite' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'toggleFavorite'],
    'toggle_lock' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'toggleLock'],
    'toggle_encryption' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'toggleEncryption'],
    'update_sort_order' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'updateSortOrder'],
    'batch_rename' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'batchRename'],
    'batch_move' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'batchMove'],
    'batch_copy' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'batchCopy'],
    'update_description' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'updateDescription'],
    'update_tags' => ['controller' => 'App\Controllers\File\FileOpController', 'method' => 'updateTags'],

    'download' => ['controller' => 'App\Controllers\File\DownloadController', 'method' => 'download'],
    'preview' => ['controller' => 'App\Controllers\File\DownloadController', 'method' => 'preview'],
    'thumbnail' => ['controller' => 'App\Controllers\File\DownloadController', 'method' => 'thumbnail'],
    'record_access' => ['controller' => 'App\Controllers\File\DownloadController', 'method' => 'recordAccess'],

    'audio_meta' => ['controller' => 'App\Controllers\File\AudioMetaController', 'method' => 'meta'],
    'audio_lyric' => ['controller' => 'App\Controllers\File\AudioMetaController', 'method' => 'lyric'],
    'audio_embedded_lyric' => ['controller' => 'App\Controllers\File\AudioMetaController', 'method' => 'embeddedLyric'],

    'create_share' => ['controller' => 'App\Controllers\Share\ShareManageController', 'method' => 'create'],
    'list_shares' => ['controller' => 'App\Controllers\Share\ShareManageController', 'method' => 'list'],
    'delete_share' => ['controller' => 'App\Controllers\Share\ShareManageController', 'method' => 'delete'],
    'toggle_share' => ['controller' => 'App\Controllers\Share\ShareManageController', 'method' => 'toggle'],
    'share_stats' => ['controller' => 'App\Controllers\Share\ShareManageController', 'method' => 'stats'],

    'share_info' => ['controller' => 'App\Controllers\Share\SharePublicController', 'method' => 'info'],
    'share_download' => ['controller' => 'App\Controllers\Share\SharePublicController', 'method' => 'download'],
    'share_direct' => ['controller' => 'App\Controllers\Share\SharePublicController', 'method' => 'directAccess'],
    'record_share_visit' => ['controller' => 'App\Controllers\Share\SharePublicController', 'method' => 'recordShareVisit'],

    // 文件信箱
    'inbox_info' => ['controller' => 'App\Controllers\Inbox\InboxController', 'method' => 'info'],
    'inbox_toggle' => ['controller' => 'App\Controllers\Inbox\InboxController', 'method' => 'toggle'],
    'inbox_regenerate' => ['controller' => 'App\Controllers\Inbox\InboxController', 'method' => 'regenerate'],
    'inbox_download' => ['controller' => 'App\Controllers\Inbox\InboxController', 'method' => 'download'],
    'inbox_move' => ['controller' => 'App\Controllers\Inbox\InboxController', 'method' => 'move'],
    'inbox_delete' => ['controller' => 'App\Controllers\Inbox\InboxController', 'method' => 'delete'],
    'inbox_upload' => ['controller' => 'App\Controllers\Inbox\InboxController', 'method' => 'upload'],
    'inbox_verify' => ['controller' => 'App\Controllers\Inbox\InboxController', 'method' => 'verify'],

    'list_trash' => ['controller' => 'App\Controllers\Trash\TrashController', 'method' => 'list'],
    'restore' => ['controller' => 'App\Controllers\Trash\TrashController', 'method' => 'restore'],
    'permanent_delete' => ['controller' => 'App\Controllers\Trash\TrashController', 'method' => 'permanentDelete'],
    'empty_trash' => ['controller' => 'App\Controllers\Trash\TrashController', 'method' => 'emptyTrash'],

    'get_config' => ['controller' => 'App\Controllers\System\ConfigController', 'method' => 'get'],
    'update_config' => ['controller' => 'App\Controllers\System\ConfigController', 'method' => 'update'],
    'get_cache_size' => ['controller' => 'App\Controllers\System\ConfigController', 'method' => 'getCacheSize'],
    'clear_cache' => ['controller' => 'App\Controllers\System\ConfigController', 'method' => 'clearCache'],
    'export_settings' => ['controller' => 'App\Controllers\System\ConfigController', 'method' => 'exportSettings'],
    'import_settings' => ['controller' => 'App\Controllers\System\ConfigController', 'method' => 'importSettings'],
    'import_config' => ['controller' => 'App\Controllers\System\ConfigController', 'method' => 'importConfig'],

    'list_logs' => ['controller' => 'App\Controllers\System\LogController', 'method' => 'list'],
    'log_stats' => ['controller' => 'App\Controllers\System\LogController', 'method' => 'stats'],
    'operation_logs' => ['controller' => 'App\Controllers\System\LogController', 'method' => 'operationLogs'],
    'log_statistics' => ['controller' => 'App\Controllers\System\LogController', 'method' => 'logStatistics'],
    'clear_logs' => ['controller' => 'App\Controllers\System\LogController', 'method' => 'clear'],

    'system_info' => ['controller' => 'App\Controllers\System\MonitorController', 'method' => 'systemInfo'],
    'disk_info' => ['controller' => 'App\Controllers\System\MonitorController', 'method' => 'diskInfo'],
    'get_disk_info' => ['controller' => 'App\Controllers\System\MonitorController', 'method' => 'getDiskInfo'],
    'storage_settings' => ['controller' => 'App\Controllers\System\MonitorController', 'method' => 'storageSettings'],
    'update_storage' => ['controller' => 'App\Controllers\System\MonitorController', 'method' => 'updateStorage'],
    'update_storage_settings' => ['controller' => 'App\Controllers\System\MonitorController', 'method' => 'updateStorageSettings'],
    'manual_update_storage' => ['controller' => 'App\Controllers\System\MonitorController', 'method' => 'manualUpdateStorage'],

    'forgot_password' => ['controller' => 'App\Controllers\Auth\LoginController', 'method' => 'forgotPassword'],
    'verify_reset' => ['controller' => 'App\Controllers\Auth\LoginController', 'method' => 'verifyReset'],

    'ai_agent_config' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'getConfig'],
    'ai_agent_save' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'saveConfig'],
    'ai_agent_fetch_models' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'fetchModels'],
    'ai_agent_test_connection' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'testConnection'],
    'ai_agent_chat' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'chat'],
    'ai_agent_chat_stream' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'chatStream'],
    'ai_agent_chat_stream_submit' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'chatStreamSubmit'],
    'ai_agent_chat_stream_progress' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'chatStreamProgress'],
    'ai_generate_title' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'generateTitle'],
    'ai_session_create' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'createSession'],
    'ai_session_list' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'listSessions'],
    'ai_session_messages' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'getSessionMessages'],
    'ai_session_delete' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'deleteSession'],
    'ai_session_update_title' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'updateSessionTitle'],
    'ai_list_models' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'listModels'],
    'ai_test_connection' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'testConnection'],

    // AI 后台任务 & 通知
    'ai_agent_chat_background' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'chatBackground'],
    'ai_agent_task_status' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'getTaskStatus'],
    'ai_notifications' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'getNotifications'],
    'ai_unread_count' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'getUnreadNotificationCount'],
    'ai_notification_read' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'markNotificationRead'],
    'ai_notification_read_all' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'markAllNotificationsRead'],
    'ai_worker_status' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'getWorkerStatus'],
    'ai_agent_cancel_task' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'cancelTask'],
    'ai_agent_convert_to_background' => ['controller' => 'App\Controllers\System\AIAgentController', 'method' => 'convertToBackground'],

    'api_get_config' => ['controller' => 'App\Controllers\System\ApiController', 'method' => 'getConfig'],
    'api_toggle' => ['controller' => 'App\Controllers\System\ApiController', 'method' => 'toggleApi'],
    'api_generate_token' => ['controller' => 'App\Controllers\System\ApiController', 'method' => 'generateToken'],
    'api_revoke_token' => ['controller' => 'App\Controllers\System\ApiController', 'method' => 'revokeToken'],
    'api_update_config' => ['controller' => 'App\Controllers\System\ApiController', 'method' => 'updateApiConfig'],

    // 广告系统
    'get_ad_config' => ['controller' => 'App\Controllers\System\AdController', 'method' => 'getConfig'],
    'toggle_ad_enabled' => ['controller' => 'App\Controllers\System\AdController', 'method' => 'toggleEnabled'],
    'dismiss_ad_prompt' => ['controller' => 'App\Controllers\System\AdController', 'method' => 'dismissPrompt'],
    'get_ads' => ['controller' => 'App\Controllers\System\AdController', 'method' => 'getAds'],

    // 系统更新
    'get_update_config' => ['controller' => 'App\Controllers\System\UpdateController', 'method' => 'getConfig'],
    'save_update_config' => ['controller' => 'App\Controllers\System\UpdateController', 'method' => 'saveConfig'],
    'check_update' => ['controller' => 'App\Controllers\System\UpdateController', 'method' => 'checkUpdate'],
    'get_update_status' => ['controller' => 'App\Controllers\System\UpdateController', 'method' => 'getStatus'],
    'apply_update' => ['controller' => 'App\Controllers\System\UpdateController', 'method' => 'applyUpdate'],
    'get_update_backups' => ['controller' => 'App\Controllers\System\UpdateController', 'method' => 'getBackups'],
    'rollback_update' => ['controller' => 'App\Controllers\System\UpdateController', 'method' => 'rollbackUpdate'],
    'get_update_history' => ['controller' => 'App\Controllers\System\UpdateController', 'method' => 'getHistory'],
    'clear_update_failed' => ['controller' => 'App\Controllers\System\UpdateController', 'method' => 'clearFailed'],
    'delete_update_backup' => ['controller' => 'App\Controllers\System\UpdateController', 'method' => 'deleteBackup'],

];

if (!isset($routeMap[$action])) {
    AsyncLogger::getInstance()->warning('Unknown action attempted', ['action' => $action]);
    Security::jsonOutput(['error' => '无效的操作请求'], 400);
}

$route = $routeMap[$action];
$controllerClass = $route['controller'];
$method = $route['method'];

try {
    $controller = new $controllerClass();
    if (defined('DEBUG') && DEBUG) {
        AsyncLogger::getInstance()->debug('API dispatch', [
            'action' => $action,
            'controller' => $controllerClass,
            'method' => $method,
        ]);
    }
    $controller->$method();
} catch (\Throwable $e) {
    // 记录详细错误到统一日志（不返回给用户）
    AsyncLogger::getInstance()->error('API exception: ' . $e->getMessage(), [
        'action' => $action,
        'controller' => $controllerClass,
        'method' => $method,
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);

    // 根据调试模式决定返回信息的详细程度
    if (defined('DEBUG') && DEBUG) {
        Security::jsonOutput([
            'error' => '服务器内部错误',
            'debug' => $e->getMessage()
        ], 500);
    } else {
        // 生产环境只返回通用错误消息
        Security::jsonOutput(['error' => '服务器内部错误，请稍后重试'], 500);
    }
}
