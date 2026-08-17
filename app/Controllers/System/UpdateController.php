<?php

namespace App\Controllers\System;

use App\Core\Security;
use Updater\State;
use Updater\Manifest;
use Updater\Checker;
use Updater\Updater;

class UpdateController extends \App\Controllers\BaseController
{
    private static bool $bootstrapped = false;

    public function __construct()
    {
        parent::__construct();
        $this->bootstrapUpdater();
    }

    /**
     * 加载更新子系统引导文件（防重复）。
     */
    private function bootstrapUpdater(): void
    {
        if (self::$bootstrapped) {
            return;
        }
        $initFile = ROOT_PATH . DIRECTORY_SEPARATOR . 'updater' . DIRECTORY_SEPARATOR . 'init.php';
        if (!is_file($initFile)) {
            return;
        }
        require_once $initFile;
        self::$bootstrapped = true;
    }

    /**
     * 输出 JSON 响应，不调用 exit/die。
     *
     * 用于 applyUpdate/rollbackUpdate 等需要在响应后继续执行的场景。
     * 与 Security::jsonOutput 不同，本方法不中断请求生命周期。
     */
    protected function respondJson($data, int $httpCode = 200): void
    {
        // 清空所有输出缓冲（包括 Security::init 启用的压缩缓冲），保证响应体立即输出
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code($httpCode);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        if (function_exists('flush')) {
            @flush();
        }
    }

    // ============================================================
    //  GET / save_update_config
    // ============================================================

    /**
     * 返回当前更新配置（前端可读）。
     */
    public function getConfig(): void
    {
        $this->requireAdmin();

        $autoCfg = Manifest::getAutoUpdateConfig();
        $config  = require ROOT_PATH . DIRECTORY_SEPARATOR . 'updater' . DIRECTORY_SEPARATOR . 'config.php';
        $lastCheck = Manifest::getLastCheck();
        $pending = Manifest::getPendingUpdate();

        Security::jsonOutput([
            'success'           => true,
            'config'            => [
                'update_enabled'  => (bool)($autoCfg['enabled'] ?? false),
                'update_channel'  => $autoCfg['channel'] ?? 'stable',
                'check_interval'  => (int)($autoCfg['checkInterval'] ?? $config['check_interval'] ?? 21600),
                'update_strategy' => $autoCfg['strategy'] ?? 'notify_only',
            ],
            'current_version'   => Manifest::getCurrentVersion(),
            'latest_version'    => $pending['latestVersion'] ?? '',
            'last_check_time'   => $lastCheck > 0 ? date('Y-m-d H:i:s', $lastCheck) : '从未检查',
            'update_source_url' => $config['update_source_url'] ?? 'https://yoliarkupdate.yoliark.com/',
            'max_backups'       => $config['max_backups'] ?? 3,
            'update_in_progress'=> State::isInProgress(),
        ]);
    }

    /**
     * 保存自动更新配置。update_source_url 不接受前端传入。
     */
    public function saveConfig(): void
    {
        $this->requireAdmin();
        $this->validateCSRF();

        $enabled    = (bool)$this->input('update_enabled', false);
        $channel    = (string)$this->input('update_channel', 'stable');
        $interval   = (int)$this->input('check_interval', 21600);
        $strategy   = (string)$this->input('update_strategy', 'notify_only');

        if (!in_array($channel, ['stable', 'beta', 'lts'], true)) {
            $channel = 'stable';
        }
        if (!in_array($strategy, ['notify_only', 'auto_download', 'auto_install'], true)) {
            $strategy = 'notify_only';
        }
        if ($interval < 3600) {
            $interval = 3600;
        }

        Manifest::setAutoUpdateConfig([
            'enabled'       => $enabled,
            'channel'       => $channel,
            'checkInterval' => $interval,
            'strategy'      => $strategy,
        ]);

        Security::jsonOutput(['success' => true, 'message' => '配置已保存']);
    }

    // ============================================================
    //  check_update
    // ============================================================

    public function checkUpdate(): void
    {
        $this->requireAdmin();
        $this->validateCSRF();

        try {
            $checker = new Checker();
            $result = $checker->check(Manifest::getCurrentVersion(), PHP_VERSION);
        } catch (\Throwable $e) {
            Security::jsonOutput(['success' => false, 'message' => '检查更新失败: ' . $e->getMessage()], 500);
        }

        Manifest::setLastCheck(time());
        $lastCheckTime = date('Y-m-d H:i:s', Manifest::getLastCheck());

        if (!empty($result['hasUpdate'])) {
            Manifest::setPendingUpdate($result);
            Manifest::setNewFeatures((array)($result['features'] ?? []));

            $packageSize = (int)($result['packageSize'] ?? 0);
            $info = [
                'version'          => $result['latestVersion'] ?? '',
                'download_url'     => $result['downloadUrl'] ?? '',
                'signature_url'    => $result['signatureUrl'] ?? '',
                'sha256'           => $result['sha256'] ?? '',
                'size'             => $packageSize,
                'size_formatted'   => Security::formatSize($packageSize),
                'features'         => $result['features'] ?? [],
                'release_notes'    => $result['releaseNotesMd'] ?? '',
                'force_update'     => (bool)($result['mandatory'] ?? false),
                'security_update'  => (bool)($result['criticalSecurityUpdate'] ?? false),
                'php_requirement'  => $result['minPhpVersion'] ?? '8.0.0',
                'release_time'     => '',
            ];

            Security::jsonOutput([
                'success'         => true,
                'has_update'      => true,
                'update_info'     => $info,
                'latest_version'  => $result['latestVersion'] ?? '',
                'last_check_time' => $lastCheckTime,
                'message'         => '发现新版本 ' . ($result['latestVersion'] ?? ''),
            ]);
        }

        Security::jsonOutput([
            'success'         => true,
            'has_update'      => false,
            'latest_version'  => $result['latestVersion'] ?? '',
            'last_check_time' => $lastCheckTime,
            'message'         => $result['message'] ?? ($result['error'] ?? '已是最新版本'),
            'error'           => $result['error'] ?? null,
        ]);
    }

    // ============================================================
    //  get_update_status
    // ============================================================

    public function getStatus(): void
    {
        $this->requireAuth();

        $state = State::load();
        $failedFlag = DATA_PATH . DIRECTORY_SEPARATOR . '.update_failed';
        $failedInfo = null;
        if (is_file($failedFlag)) {
            $decoded = json_decode((string)file_get_contents($failedFlag), true);
            if (is_array($decoded)) {
                $failedInfo = $decoded;
            }
        }

        $pending = Manifest::getPendingUpdate();
        $error = $state['error'] ?? null;
        if ($error === null && $failedInfo !== null) {
            $error = $failedInfo['error'] ?? null;
        }

        Security::jsonOutput([
            'success'         => true,
            'phase'           => $state['phase'] ?? 'idle',
            'progress'        => (int)($state['progress'] ?? 0),
            'detail'          => $state['message'] ?? '',
            'error'           => $error,
            'message'         => $failedInfo ? ($failedInfo['error'] ?? '') : '',
            'current_version' => Manifest::getCurrentVersion(),
            'target_version'  => $pending['latestVersion'] ?? '',
            'maintenance'     => \Updater\Maintenance::isActive(),
            'in_progress'     => State::isInProgress(),
            'failed'          => $failedInfo,
            'pending'         => $pending,
        ]);
    }

    // ============================================================
    //  apply_update
    // ============================================================

    public function applyUpdate(): void
    {
        $this->requireAdmin();
        $this->validateCSRF();

        // 启动锁预占（防并发竞态：避免两个请求都过 isInProgress 检查后互相覆盖 state）
        if (!Updater::tryAcquireLock()) {
            $this->respondJson(['success' => false, 'message' => '已有更新正在进行中'], 409);
            return;
        }

        $pending = Manifest::getPendingUpdate();
        if (!$pending) {
            Updater::releaseLock();
            $this->respondJson(['success' => false, 'message' => '没有待应用的更新，请先检查更新'], 400);
            return;
        }

        @set_time_limit(0);
        @ignore_user_abort(true);
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        // 预先标记，前端立即可见
        State::setPhase(State::DOWNLOADING, ['progress' => 0, 'message' => 'Starting update']);

        // 输出响应但不 exit
        $this->respondJson(['success' => true, 'message' => '更新已开始']);

        // FPM 提前结束请求，让浏览器收到响应
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        // 在后台执行更新（doUpdate 内部会复用已持有的锁，finally 释放）
        try {
            $updater = new Updater();
            $updater->doUpdate();
        } catch (\Throwable $e) {
            State::setPhase(State::FAILED, ['error' => $e->getMessage()]);
            $flagFile = DATA_PATH . DIRECTORY_SEPARATOR . '.update_failed';
            @file_put_contents(
                $flagFile,
                json_encode(['error' => $e->getMessage(), 'timestamp' => time()], JSON_UNESCAPED_UNICODE),
                LOCK_EX
            );
            // 兜底释放锁（正常路径由 doUpdate 的 finally 释放）
            Updater::releaseLock();
        }
    }

    // ============================================================
    //  rollback_update
    // ============================================================

    public function rollbackUpdate(): void
    {
        $this->requireAdmin();
        $this->validateCSRF();

        // 启动锁预占（防并发竞态）
        if (!Updater::tryAcquireLock()) {
            $this->respondJson(['success' => false, 'message' => '已有更新正在进行中'], 409);
            return;
        }

        $backupDir = (string)$this->input('backup_id', '');
        if ($backupDir === '') {
            $backupDir = (string)$this->input('backupDir', '');
        }
        if ($backupDir === '') {
            // 未指定备份：自动选择最近的一个
            $backups = Manifest::getBackups();
            if (!empty($backups)) {
                usort($backups, function ($a, $b) {
                    return (int)($b['createdAt'] ?? 0) <=> (int)($a['createdAt'] ?? 0);
                });
                $backupDir = (string)($backups[0]['dir'] ?? '');
            }
            if ($backupDir === '') {
                Updater::releaseLock();
                $this->respondJson(['success' => false, 'message' => '没有可用的备份'], 400);
                return;
            }
        }

        // 仅允许 backups 目录下的目录名
        $safeName = basename($backupDir);
        $absPath = UPDATE_BACKUPS_PATH . DIRECTORY_SEPARATOR . $safeName;
        if ($safeName === '' || $safeName === '.' || $safeName === '..' || !is_dir($absPath)) {
            Updater::releaseLock();
            $this->respondJson(['success' => false, 'message' => '备份不存在'], 404);
            return;
        }

        @set_time_limit(0);
        @ignore_user_abort(true);
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        State::setPhase(State::MAINTENANCE_ON, ['progress' => 0, 'message' => 'Starting rollback']);

        $this->respondJson(['success' => true, 'message' => '回滚已开始']);

        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        try {
            $updater = new Updater();
            $updater->doRollback($safeName);
        } catch (\Throwable $e) {
            State::setPhase(State::FAILED, ['error' => $e->getMessage()]);
            $flagFile = DATA_PATH . DIRECTORY_SEPARATOR . '.update_failed';
            @file_put_contents(
                $flagFile,
                json_encode(['error' => $e->getMessage(), 'timestamp' => time()], JSON_UNESCAPED_UNICODE),
                LOCK_EX
            );
            Updater::releaseLock();
        }
    }

    // ============================================================
    //  get_update_backups / delete_update_backup
    // ============================================================

    public function getBackups(): void
    {
        $this->requireAdmin();

        $backups = Manifest::getBackups();
        $enriched = [];
        foreach ($backups as $b) {
            $dir = (string)($b['dir'] ?? '');
            $abs = UPDATE_BACKUPS_PATH . DIRECTORY_SEPARATOR . $dir;
            $exists = is_dir($abs);
            $size = 0;
            if ($exists) {
                $size = isset($b['size']) ? (int)$b['size'] : \Updater\Backup::dirSize($abs);
            }
            $createdAt = (int)($b['createdAt'] ?? 0);
            $enriched[] = [
                'id'             => $dir,
                'dir'            => $dir,
                'version'        => $b['version'] ?? '',
                'size'           => $size,
                'size_formatted' => Security::formatSize($size),
                'created_at'     => $createdAt > 0 ? date('Y-m-d H:i:s', $createdAt) : '-',
                'time'           => $createdAt,
                'exists'         => $exists,
            ];
        }

        Security::jsonOutput([
            'success' => true,
            'backups' => $enriched,
        ]);
    }

    public function deleteBackup(): void
    {
        $this->requireAdmin();
        $this->validateCSRF();

        $dir = (string)$this->input('backup_id', '');
        if ($dir === '') {
            $dir = (string)$this->input('backupDir', '');
        }
        $safeName = basename($dir);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            Security::jsonOutput(['success' => false, 'message' => '无效的备份名称'], 400);
        }

        $abs = UPDATE_BACKUPS_PATH . DIRECTORY_SEPARATOR . $safeName;
        if (!is_dir($abs)) {
            Security::jsonOutput(['success' => false, 'message' => '备份不存在'], 404);
        }

        // 手动递归删除该备份目录
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($rii as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }
        @rmdir($abs);

        Manifest::removeBackup($safeName);

        Security::jsonOutput(['success' => true, 'message' => '备份已删除']);
    }

    // ============================================================
    //  get_update_history
    // ============================================================

    public function getHistory(): void
    {
        $this->requireAdmin();

        $history = Manifest::getHistory();
        $enriched = [];
        foreach ($history as $h) {
            $ts = (int)($h['timestamp'] ?? 0);
            $h['created_at'] = $ts > 0 ? date('Y-m-d H:i:s', $ts) : '-';
            $h['time'] = $ts;
            $enriched[] = $h;
        }

        Security::jsonOutput([
            'success' => true,
            'history' => $enriched,
        ]);
    }

    // ============================================================
    //  clear_update_failed
    // ============================================================

    public function clearFailed(): void
    {
        $this->requireAdmin();
        $this->validateCSRF();

        $flag = DATA_PATH . DIRECTORY_SEPARATOR . '.update_failed';
        if (is_file($flag)) {
            @unlink($flag);
        }
        State::reset();

        Security::jsonOutput(['success' => true, 'message' => '失败状态已清除']);
    }
}
