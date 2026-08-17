<?php

// 检查 PHP 版本要求
if (PHP_VERSION_ID < 80000) {
    die('柚舟Cloud 需要 PHP 8.0 或更高版本，当前版本：' . PHP_VERSION);
}

// FrankenPHP 静态二进制模式：ROOT_PATH 指向当前工作目录（磁盘可写路径）
// PHP 代码从 embed 加载（虚拟路径），数据文件写到磁盘
if (getenv('FRANKENPHP_EMBED') === '1' || isset($_SERVER['FRANKENPHP_VERSION'])) {
    define('ROOT_PATH', getcwd());
} else {
    define('ROOT_PATH', dirname(__DIR__));
}
define('APP_PATH', ROOT_PATH . '/app');
define('FRAMEWORK_PATH', ROOT_PATH . '/framework');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('DATA_PATH', STORAGE_PATH . '/data');
define('FILES_PATH', STORAGE_PATH . '/files');
define('TRASH_PATH', STORAGE_PATH . '/trash');
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('PANCLOUD_VERSION', '1.0.0');

// ── 安全增强：使用随机文件名防止直接猜测下载 ──
// 随机名称映射存储在 .filemap 中，格式：{"db":"xxx.db","config":"xxx.json"}
define('FILEMAP_PATH', DATA_PATH . '/.filemap');

function _getRandomFileNames() {
    $map = ['db' => 'pancloud.db', 'config' => 'config.json']; // 默认名称（兼容旧安装）
    if (file_exists(FILEMAP_PATH)) {
        $content = @file_get_contents(FILEMAP_PATH);
        $decoded = $content !== false ? json_decode($content, true) : null;
        if (is_array($decoded)) {
            $map = array_merge($map, $decoded);
        }
    } else {
        // 首次运行：生成随机文件名映射，确保后续 define() 使用一致的名称
        // 关键：必须在 define(CONFIG_FILE/DB_PATH) 之前完成，否则安装后常量与 .filemap 不一致
        if (!is_dir(DATA_PATH)) {
            @mkdir(DATA_PATH, 0755, true);
        }
        $map = [
            'db' => 'pancloud_' . bin2hex(random_bytes(16)) . '.db',
            'config' => 'config_' . bin2hex(random_bytes(16)) . '.json',
        ];
        @file_put_contents(FILEMAP_PATH, json_encode($map), LOCK_EX);
    }
    return $map;
}

$_fileMap = _getRandomFileNames();
$configFile = DATA_PATH . '/' . $_fileMap['config'];
$dbType = 'sqlite';
if (file_exists($configFile)) {
    $configData = json_decode(file_get_contents($configFile), true);
    if (isset($configData['database']['type'])) {
        $dbType = $configData['database']['type'];
    }
}

if ($dbType === 'sqlite') {
    define('DB_PATH', DATA_PATH . '/' . $_fileMap['db']);
} else {
    define('DB_PATH', '');
}
define('CONFIG_FILE', $configFile);
define('DEBUG', filter_var(getenv('PANCLOUD_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN));

// 错误处理由 ErrorHandler 统一管理，这里只设置基础配置
if (!DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// 全局不设 set_time_limit(0)，改为在具体耗时操作入口设置
// 参见：uploadFile/mergeChunks/sendFile/chatStream

spl_autoload_register(function ($class) {
    $prefix = 'Framework\\';
    $baseDir = FRAMEWORK_PATH . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = APP_PATH . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
        return;
    }

    $parts = explode('\\', $relativeClass);
    $className = array_pop($parts);
    $searchDir = $baseDir;
    foreach ($parts as $part) {
        $found = false;
        if (is_dir($searchDir . $part)) {
            $searchDir .= $part . '/';
            $found = true;
        } else {
            $entries = scandir($searchDir);
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                if (strtolower($entry) === strtolower($part) && is_dir($searchDir . $entry)) {
                    $searchDir .= $entry . '/';
                    $found = true;
                    break;
                }
            }
        }
        if (!$found) return;
    }

    $entries = scandir($searchDir);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $entryName = pathinfo($entry, PATHINFO_FILENAME);
        if (strtolower($entryName) === strtolower($className) && pathinfo($entry, PATHINFO_EXTENSION) === 'php') {
            require $searchDir . $entry;
            return;
        }
    }
});

require_once FRAMEWORK_PATH . '/Support/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', 1);
    ini_set('session.gc_maxlifetime', 7200);
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor', 1000);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_trans_sid', 0);
    ini_set('session.lazy_write', 0);

    $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
    ini_set('session.cookie_secure', $isSecure ? 1 : 0);

    // 会话存储：仅当 Redis/Memcached 服务实际可达时才启用，避免扩展已加载但服务未运行导致 session 数据静默丢失
    $sessionSaveHandler = 'files';
    $sessionSavePath = '';
    if (extension_loaded('redis')) {
        try {
            $redis = new \Redis();
            if (@$redis->connect('127.0.0.1', 6379, 1.0)) {
                $redis->close();
                $sessionSaveHandler = 'redis';
                $sessionSavePath = 'tcp://127.0.0.1:6379?database=0';
            }
        } catch (\Throwable $e) {}
    } elseif (extension_loaded('memcached')) {
        try {
            $memcached = new \Memcached();
            if (@$memcached->addServer('localhost', 11211)) {
                $versions = @$memcached->getVersion();
                if ($versions !== false && !empty($versions)) {
                    $sessionSaveHandler = 'memcached';
                    $sessionSavePath = 'localhost:11211';
                }
            }
        } catch (\Throwable $e) {}
    }

    ini_set('session.save_handler', $sessionSaveHandler);
    if ($sessionSavePath !== '') {
        ini_set('session.save_path', $sessionSavePath);
    }

    session_name('PANCLOUD_SID');
    session_start();

    if (empty($_SESSION['created_at'])) {
        $_SESSION['created_at'] = time();
    }
}

if (!is_dir(DATA_PATH)) {
    mkdir(DATA_PATH, 0755, true);
}
if (!is_dir(FILES_PATH)) {
    mkdir(FILES_PATH, 0755, true);
}
if (!is_dir(TRASH_PATH)) {
    mkdir(TRASH_PATH, 0755, true);
}
if (!is_dir(UPLOAD_PATH)) {
    mkdir(UPLOAD_PATH, 0755, true);
}

$htaccessContent = "Deny from all\n";
foreach ([FILES_PATH, TRASH_PATH, UPLOAD_PATH, DATA_PATH] as $dir) {
    $htFile = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htFile)) {
        file_put_contents($htFile, $htaccessContent);
    }
}
