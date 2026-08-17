<?php

namespace App\Core;

class Config
{
    private static $instance = null;
    private $config = [];

    private function __construct()
    {
        $this->config = [
            'app_name' => '柚舟Cloud',
            'debug' => false,
            'timezone' => 'Asia/Shanghai',
            'database' => [
                'type' => 'sqlite',
                'config' => [],
            ],
            'max_upload_size' => 500 * 1024 * 1024,
            'chunk_size' => 5 * 1024 * 1024,
            'allowed_extensions' => [],
            'blocked_extensions' => ['php', 'php3', 'php4', 'php5', 'phtml', 'pht', 'phar', 'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'sh', 'htaccess', 'ini'],
            'session_lifetime' => 7200,
            'csrf_token_name' => '_csrf_token',
            'share_link_length' => 12,
            'share_default_expire' => 7 * 24 * 3600,
            'trash_retention_days' => 30,
            'thumbnail_size' => 64,
            'thumbnail_max_source_size' => 10 * 1024 * 1024,
            'preview_max_size' => 150 * 1024 * 1024,
            'preview_max_size_text' => 2 * 1024 * 1024,
            'preview_max_size_image' => 10 * 1024 * 1024,
            'preview_max_size_media' => 150 * 1024 * 1024,
            'preview_max_size_office' => 150 * 1024 * 1024,
            'preview_max_size_pdf' => 150 * 1024 * 1024,
            'login_max_attempts' => 5,
            'login_lockout_time' => 900,
            'password_min_length' => 8,
            'download_rate_limit' => 30,
            'download_rate_window' => 60,
            'delete_rate_limit' => 20,
            'delete_rate_window' => 60,
            'storage_reserve_mb' => 500,
            'storage_update_threshold' => 1,
            'adaptive_rate_limit_enabled' => true,
            'adaptive_rate_limit_cleanup_interval' => 3600,
            'adaptive_rate_limit_tiny_threshold' => 256 * 1024,
            'adaptive_rate_limit_small_threshold' => 1 * 1024 * 1024,
            'adaptive_rate_limit_medium_threshold' => 10 * 1024 * 1024,
            'adaptive_rate_limit_large_threshold' => 100 * 1024 * 1024,
            'adaptive_rate_limit_default_max_tokens' => 300,
            'adaptive_rate_limit_default_refill_rate' => 5.0,
            'adaptive_rate_limit_chunk_max_tokens' => 500,
            'adaptive_rate_limit_chunk_refill_rate' => 10.0,
            'adaptive_rate_limit_tiny_burst_max_tokens' => 1000,
            'adaptive_rate_limit_tiny_burst_refill_rate' => 15.0,
            'adaptive_rate_limit_small_burst_max_tokens' => 600,
            'adaptive_rate_limit_small_burst_refill_rate' => 8.0,
            'mail_system' => [
                'enabled'   => false,
                'api_url'   => '',
                'token'     => '',
                'device_id' => '',
            ],
            'api_enabled' => false,
            'api_token' => '',
            'api_token_created_at' => 0,
            'api_rate_limit' => 60,
            'api_rate_window' => 60,
            'benchmark' => [
                'disk_write_mbps' => 0,
                'disk_read_mbps' => 0,
                'db_writes_per_sec' => 0,
                'db_reads_per_sec' => 0,
                'hash_md5_mbps' => 0,
                'hash_sha256_mbps' => 0,
                'tier' => 'unknown',
                'performance_score' => 0,
                'calibrated_upload_max_tokens' => 0,
                'calibrated_upload_refill_rate' => 0,
                'calibrated_chunk_max_tokens' => 0,
                'calibrated_chunk_refill_rate' => 0,
                'benchmarked_at' => 0,
            ],
            'inbox_enabled' => false,
            'inbox_url' => '',
            'ad_enabled' => false,
            'ad_prompt_dismissed' => false,
        ];

        if (file_exists(CONFIG_FILE)) {
            $saved = json_decode(file_get_contents(CONFIG_FILE), true);
            if (is_array($saved)) {
                $this->config = array_merge($this->config, $saved);
            }
        }

        $this->config['timezone'] = 'Asia/Shanghai';
        date_default_timezone_set($this->config['timezone']);

        if ($this->config['debug'] === true) {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        } else {
            error_reporting(E_ALL);
            ini_set('display_errors', 0);
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    public function get($key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    public function set($key, $value)
    {
        $keys = explode('.', $key);
        $config = &$this->config;

        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $config[$k] = $value;
            } else {
                if (!isset($config[$k]) || !is_array($config[$k])) {
                    $config[$k] = [];
                }
                $config = &$config[$k];
            }
        }
    }

    public function save()
    {
        if (!is_dir(DATA_PATH)) {
            mkdir(DATA_PATH, 0755, true);
        }
        $this->config['timezone'] = 'Asia/Shanghai';
        return file_put_contents(CONFIG_FILE, json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
    }

    public function getAll()
    {
        return $this->config;
    }

    public function isInstalled()
    {
        if (!file_exists(CONFIG_FILE)) {
            return false;
        }

        $configData = json_decode(file_get_contents(CONFIG_FILE), true);
        if (!is_array($configData) || !isset($configData['database']['type'])) {
            return false;
        }

        $dbType = $configData['database']['type'];

        if ($dbType === 'sqlite') {
            return file_exists(DB_PATH);
        }

        // MySQL / PostgreSQL：验证数据库连接是否可用
        $dbConfig = $configData['database']['config'] ?? [];
        try {
            if ($dbType === 'mysql') {
                $host = $dbConfig['host'] ?? '127.0.0.1';
                $port = $dbConfig['port'] ?? 3306;
                $database = $dbConfig['database'] ?? 'pancloud';
                $username = $dbConfig['username'] ?? 'root';
                $password = $dbConfig['password'] ?? '';
                $charset = $dbConfig['charset'] ?? 'utf8mb4';
                $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
            } elseif ($dbType === 'pgsql') {
                $host = $dbConfig['host'] ?? '127.0.0.1';
                $port = $dbConfig['port'] ?? 5432;
                $database = $dbConfig['database'] ?? 'pancloud';
                $username = $dbConfig['username'] ?? 'postgres';
                $password = $dbConfig['password'] ?? '';
                $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
            } else {
                return false;
            }

            $pdo = new \PDO($dsn, $username, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 3,
            ]);
            $stmt = $pdo->query('SELECT 1');
            return $stmt !== false && $stmt->fetch() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
