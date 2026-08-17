<?php

namespace Updater;

/**
 * 更新编排类。
 *
 * 流程：flock → 下载 → 校验 → 维护 → 停 Worker → 备份 → 应用(原子+废弃清理) → 启 Worker → 健康检查 → 自动回滚。
 *
 * 稳定性设计（对照 update-server-guide.md 第 10 节）：
 * - 并发锁：进程内静态持有，acquireLock 失败不污染状态（避免并发请求互相覆盖 state）。
 * - 原子文件应用：逐文件先写 .updater-tmp 再 rename，避免半更新状态。
 * - 废弃文件清理：依据包内 MANIFEST.json 删除 ROOT_PATH 中不在清单的旧文件。
 * - 权限保留：复制时保留源文件权限位。
 * - 自动回滚：健康检查失败从备份恢复并回退版本号，二次健康检查。
 */
class Updater
{
    private array $config;
    private State $state;
    private Manifest $manifest;
    private Checker $checker;
    private Downloader $downloader;
    private Verifier $verifier;
    private Backup $backup;
    private Maintenance $maintenance;
    private HealthCheck $healthCheck;

    /** @var resource|null */
    private static $lockFp = null;

    /** 应用文件时跳过的相对路径条目（用户数据、子系统自身、开发产物、敏感配置） */
    private const APPLY_SKIP_DIRS = [
        'updater', 'storage', 'uploads', '.git', '.trae', 'node_modules',
    ];

    /** 应用文件时跳过的具体文件名 */
    private const APPLY_SKIP_FILES = [
        'config.json', '.maintenance', '.instance_id',
        'MANIFEST.json',
    ];

    /** 应用文件时跳过的文件名前缀 */
    private const APPLY_SKIP_PREFIXES = ['.worker_', '.updater-tmp-'];

    public function __construct()
    {
        $this->config      = $this->loadConfig();
        $this->state       = new State();
        $this->manifest    = new Manifest();
        $this->checker     = new Checker($this->config['update_source_url'] ?? null);
        $this->downloader  = new Downloader(
            (int)($this->config['download_retry'] ?? 3),
            (int)($this->config['download_timeout'] ?? 300)
        );
        $this->verifier    = new Verifier();
        $this->backup      = new Backup();
        $this->maintenance = new Maintenance();
        $this->healthCheck = new HealthCheck();
    }

    // ============================================================
    //  并发锁（进程内静态持有）
    // ============================================================

    /**
     * 尝试获取更新锁（非阻塞）。已持有时直接返回 true。
     */
    public static function tryAcquireLock(): bool
    {
        if (self::$lockFp !== null) {
            return true;
        }
        $lockFile = UPDATES_PATH . DIRECTORY_SEPARATOR . '.update.lock';
        if (!is_dir(dirname($lockFile))) {
            @mkdir(dirname($lockFile), 0755, true);
        }
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            return false;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false;
        }
        self::$lockFp = $fp;
        return true;
    }

    public static function isLocked(): bool
    {
        return self::$lockFp !== null;
    }

    public static function releaseLock(): void
    {
        if (self::$lockFp !== null) {
            @flock(self::$lockFp, LOCK_UN);
            @fclose(self::$lockFp);
            self::$lockFp = null;
        }
    }

    // ============================================================
    //  完整更新流程
    // ============================================================

    /**
     * 完整更新流程。
     *
     * @return array{ok: bool, error?: string}
     */
    public function doUpdate(): array
    {
        // 锁：若未持有则尝试获取；失败时不修改状态（避免并发请求互相覆盖）
        if (!self::isLocked() && !self::tryAcquireLock()) {
            return ['ok' => false, 'error' => 'Another update is in progress'];
        }

        $backupDir    = null;
        $extractedDir = '';
        $pending      = Manifest::getPendingUpdate();

        try {
            if (!$pending) {
                State::setPhase(State::FAILED, ['error' => 'No pending update']);
                return ['ok' => false, 'error' => 'No pending update'];
            }

            $packagePath = UPDATE_STAGING_PATH . DIRECTORY_SEPARATOR . 'package.zip';
            $downloadUrl = (string)($pending['downloadUrl'] ?? '');
            $sha256      = (string)($pending['sha256'] ?? '');
            $sigUrl      = (string)($pending['signatureUrl'] ?? '');
            $minPhp      = (string)($pending['minPhpVersion'] ?? '8.0.0');
            $newVersion  = (string)($pending['latestVersion'] ?? '');
            $packageSize = (int)($pending['packageSize'] ?? 0);

            if ($downloadUrl === '' || $sha256 === '' || $sigUrl === '') {
                State::setPhase(State::FAILED, ['error' => 'Invalid pending update data']);
                return ['ok' => false, 'error' => 'Invalid pending update data'];
            }

            // 1. 下载
            State::setPhase(State::DOWNLOADING, ['progress' => 0]);
            State::setProgress(5, 'Downloading package');
            if (!$this->downloader->download($downloadUrl, $packagePath, $sha256, $packageSize)) {
                throw new \RuntimeException('Failed to download or verify package SHA256');
            }

            // 2. 校验
            State::setPhase(State::VERIFYING);
            State::setProgress(30, 'Verifying package integrity');
            $result = $this->verifier->verify($packagePath, [
                'sha256'        => $sha256,
                'signatureUrl'  => $sigUrl,
                'minPhpVersion' => $minPhp,
            ]);
            if (!$result['valid']) {
                throw new \RuntimeException('Verification failed: ' . implode('; ', $result['errors']));
            }
            $extractedDir  = $result['extractedDir'];
            $manifestFiles = $result['manifestFiles'] ?? [];

            // 3. 维护模式
            State::setPhase(State::MAINTENANCE_ON);
            State::setProgress(45, 'Enabling maintenance mode');
            Maintenance::enable(['reason' => 'update_to_' . $newVersion]);

            // 4. 停 Worker
            State::setPhase(State::STOPPING_WORKER);
            State::setProgress(50, 'Stopping worker');
            $this->stopWorker();

            // 5. 备份
            State::setPhase(State::BACKING_UP);
            State::setProgress(60, 'Creating backup');
            $backupDir = $this->backup->create();

            // 6. 应用文件（原子 + 废弃清理）
            State::setPhase(State::APPLYING);
            State::setProgress(70, 'Applying files');
            $this->applyFiles($extractedDir, $manifestFiles);

            // 7. 重启 Worker
            State::setPhase(State::RESTARTING);
            State::setProgress(85, 'Restarting worker');
            $this->startWorker();

            // 8. 关闭维护
            Maintenance::disable();

            // 9. 健康检查
            State::setPhase(State::HEALTH_CHECK);
            State::setProgress(95, 'Running health check');
            $health = $this->healthCheck->check();

            if ($health['healthy']) {
                // 10. 完成
                State::setPhase(State::COMPLETED);
                State::setProgress(100, 'Update completed');

                $currentVersion = Manifest::getCurrentVersion();
                Manifest::setCurrentVersion($newVersion);
                Manifest::setPendingUpdate(null);
                Manifest::setNewFeatures((array)($pending['features'] ?? []));
                Manifest::addBackup([
                    'version'   => $currentVersion,
                    'dir'       => basename($backupDir),
                    'size'      => Backup::dirSize($backupDir),
                    'createdAt' => time(),
                ]);
                Manifest::pruneBackups((int)($this->config['max_backups'] ?? 3));
                Manifest::addHistory([
                    'from_version' => $currentVersion,
                    'to_version'   => $newVersion,
                    'timestamp'    => time(),
                    'result'       => 'success',
                    'error'        => '',
                ]);

                return ['ok' => true];
            }

            // 健康检查失败 → 自动回滚
            State::setPhase(State::ROLLING_BACK);
            State::setProgress(90, 'Health check failed, rolling back');
            $this->rollbackInternal($backupDir);

            // 二次健康检查
            $health2 = $this->healthCheck->check();
            $rollbackError = $health2['healthy']
                ? 'Rolled back after health check failure'
                : 'Rollback failed too: ' . implode('; ', $health2['errors']);

            State::setPhase(State::FAILED, ['error' => $rollbackError]);
            Manifest::addHistory([
                'from_version' => Manifest::getCurrentVersion(),
                'to_version'   => $newVersion,
                'timestamp'    => time(),
                'result'       => 'failed',
                'error'        => $rollbackError,
            ]);
            $this->writeFailedFlag($rollbackError);

            return ['ok' => false, 'error' => $rollbackError];

        } catch (\Throwable $e) {
            $errMsg = $e->getMessage();

            // 如有备份则尝试回滚
            if ($backupDir !== null && is_dir($backupDir)) {
                try {
                    $this->rollbackInternal($backupDir);
                    $errMsg .= ' (rolled back to backup)';
                } catch (\Throwable $rbErr) {
                    $errMsg .= ' (rollback also failed: ' . $rbErr->getMessage() . ')';
                }
            }

            Maintenance::disable();
            $this->startWorker();

            State::setPhase(State::FAILED, ['error' => $errMsg]);
            Manifest::addHistory([
                'from_version' => Manifest::getCurrentVersion(),
                'to_version'   => $pending['latestVersion'] ?? '',
                'timestamp'    => time(),
                'result'       => 'failed',
                'error'        => $errMsg,
            ]);
            $this->writeFailedFlag($errMsg);

            return ['ok' => false, 'error' => $errMsg];
        } finally {
            self::releaseLock();
        }
    }

    // ============================================================
    //  回滚流程
    // ============================================================

    /**
     * 回滚流程：从指定备份目录恢复。
     *
     * @return array{ok: bool, error?: string}
     */
    public function doRollback(string $backupDir): array
    {
        if (!self::isLocked() && !self::tryAcquireLock()) {
            return ['ok' => false, 'error' => 'Another update is in progress'];
        }

        try {
            $safeName   = basename($backupDir);
            $absBackup  = UPDATE_BACKUPS_PATH . DIRECTORY_SEPARATOR . $safeName;
            if (!is_dir($absBackup)) {
                State::setPhase(State::FAILED, ['error' => 'Backup not found: ' . $backupDir]);
                return ['ok' => false, 'error' => 'Backup not found'];
            }

            State::setPhase(State::MAINTENANCE_ON);
            Maintenance::enable(['reason' => 'rollback']);

            State::setPhase(State::STOPPING_WORKER);
            $this->stopWorker();

            State::setPhase(State::ROLLING_BACK);
            $this->backup->restore($absBackup);

            // 回退版本号（从备份目录名解析 {timestamp}_{version}）
            $restoredVersion = $this->versionFromBackupDir($absBackup);
            if ($restoredVersion !== '') {
                Manifest::setCurrentVersion($restoredVersion);
            }

            State::setPhase(State::RESTARTING);
            $this->startWorker();

            Maintenance::disable();

            State::setPhase(State::HEALTH_CHECK);
            $health = $this->healthCheck->check();

            if ($health['healthy']) {
                State::setPhase(State::COMPLETED_ROLLED_BACK);
                State::setProgress(100, 'Rollback completed');
                Manifest::addHistory([
                    'from_version' => '',
                    'to_version'   => $restoredVersion !== '' ? $restoredVersion : $safeName,
                    'timestamp'    => time(),
                    'result'       => 'rolled_back',
                    'error'        => '',
                ]);
                return ['ok' => true];
            }

            $err = 'Rollback health check failed: ' . implode('; ', $health['errors']);
            State::setPhase(State::FAILED, ['error' => $err]);
            Manifest::addHistory([
                'from_version' => '',
                'to_version'   => $restoredVersion !== '' ? $restoredVersion : $safeName,
                'timestamp'    => time(),
                'result'       => 'failed',
                'error'        => $err,
            ]);
            $this->writeFailedFlag($err);
            return ['ok' => false, 'error' => $err];

        } catch (\Throwable $e) {
            $errMsg = $e->getMessage();
            Maintenance::disable();
            $this->startWorker();
            State::setPhase(State::FAILED, ['error' => $errMsg]);
            Manifest::addHistory([
                'from_version' => '',
                'to_version'   => $backupDir,
                'timestamp'    => time(),
                'result'       => 'failed',
                'error'        => $errMsg,
            ]);
            $this->writeFailedFlag($errMsg);
            return ['ok' => false, 'error' => $errMsg];
        } finally {
            self::releaseLock();
        }
    }

    // ============================================================
    //  Worker 控制（标志文件方式，不依赖 proc_open）
    // ============================================================

    public function stopWorker(): void
    {
        $stopFile = DATA_PATH . DIRECTORY_SEPARATOR . '.worker_stop';
        @file_put_contents($stopFile, (string)time(), LOCK_EX);
        // 不等待 heartbeat 消失：worker 会在下次检查时自行退出
        // 避免在 FPM worker 内 sleep 阻塞导致 worker 池耗尽
    }

    public function startWorker(): void
    {
        $stopFile = DATA_PATH . DIRECTORY_SEPARATOR . '.worker_stop';
        if (is_file($stopFile)) {
            @unlink($stopFile);
        }
    }

    // ============================================================
    //  文件应用（原子 + 废弃清理 + 权限保留）
    // ============================================================

    /**
     * 应用解压目录中的文件到 ROOT_PATH。
     *
     * @param string $extractedDir  解压目录
     * @param array  $manifestFiles 包内 MANIFEST.json 的文件列表（用于废弃清理）
     */
    public function applyFiles(string $extractedDir, array $manifestFiles = [], bool $invalidateCache = true): void
    {
        if (!is_dir($extractedDir)) {
            throw new \RuntimeException('Extracted directory not found: ' . $extractedDir);
        }

        $this->copyTree($extractedDir, ROOT_PATH, $invalidateCache);

        if (!empty($manifestFiles)) {
            $this->pruneObsoleteFiles($extractedDir, $manifestFiles);
        }

        clearstatcache(true);
    }

    /**
     * 递归复制目录，文件采用 .updater-tmp + rename 原子替换，保留权限。
     */
    private function copyTree(string $src, string $dst, bool $invalidateCache = true): void
    {
        if (!is_dir($src)) {
            return;
        }
        if (!is_dir($dst)) {
            @mkdir($dst, 0755, true);
        }

        $items = @scandir($src);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if ($this->shouldSkip($item)) {
                continue;
            }

            $srcPath = $src . DIRECTORY_SEPARATOR . $item;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $item;

            if (is_dir($srcPath)) {
                $this->copyTree($srcPath, $dstPath, $invalidateCache);
            } elseif (is_file($srcPath)) {
                $this->copyFileAtomic($srcPath, $dstPath, $invalidateCache);
            }
        }
    }

    /**
     * 原子复制：写临时文件 → 保留权限 → rename 替换目标。
     */
    private function copyFileAtomic(string $srcPath, string $dstPath, bool $invalidateCache = true): void
    {
        if (!is_dir(dirname($dstPath))) {
            @mkdir(dirname($dstPath), 0755, true);
        }

        $tmpPath = $dstPath . '.updater-tmp-' . bin2hex(random_bytes(4));
        if (!@copy($srcPath, $tmpPath)) {
            // 临时写失败：回退直接覆盖
            @copy($srcPath, $dstPath);
            // 逐文件清除 OPcache（替代全量 opcache_reset，避免 FPM 雪崩）
            if ($invalidateCache && function_exists('opcache_invalidate')) {
                @opcache_invalidate($dstPath, true);
            }
            return;
        }

        // 保留源文件权限位
        $perm = @fileperms($srcPath);
        if ($perm !== false) {
            @chmod($tmpPath, $perm & 0777);
        }

        if (!@rename($tmpPath, $dstPath)) {
            // rename 失败（跨设备等）：回退直接覆盖
            @unlink($tmpPath);
            @copy($srcPath, $dstPath);
            if ($perm !== false) {
                @chmod($dstPath, $perm & 0777);
            }
        }

        // 逐文件清除 OPcache（替代全量 opcache_reset，避免 FPM 雪崩）
        if ($invalidateCache && function_exists('opcache_invalidate')) {
            @opcache_invalidate($dstPath, true);
        }
    }

    /**
     * 废弃文件清理：在包覆盖的顶层目录范围内，删除 ROOT_PATH 中
     * 存在但不在 MANIFEST.json 文件清单中的文件。
     */
    private function pruneObsoleteFiles(string $extractedDir, array $manifestFiles): void
    {
        $validSet = [];
        foreach ($manifestFiles as $f) {
            $rel = str_replace('\\', '/', (string)($f['path'] ?? ''));
            if ($rel !== '') {
                $validSet[$rel] = true;
            }
        }
        if (empty($validSet)) {
            return;
        }

        $tops = @scandir($extractedDir);
        if ($tops === false) {
            return;
        }
        foreach ($tops as $top) {
            if ($top === '.' || $top === '..') {
                continue;
            }
            if ($this->shouldSkip($top)) {
                continue;
            }
            $srcTop = $extractedDir . DIRECTORY_SEPARATOR . $top;
            $dstTop = ROOT_PATH . DIRECTORY_SEPARATOR . $top;
            // 仅清理包内存在的目录（限定范围，避免误删用户数据）
            if (is_dir($srcTop) && is_dir($dstTop)) {
                $this->pruneDirectory($dstTop, $top, $validSet);
            }
        }
    }

    /**
     * 递归清理目录中不在清单的文件，清理后空目录一并删除。
     */
    private function pruneDirectory(string $dir, string $relPrefix, array $validSet): void
    {
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            $rel  = $relPrefix . '/' . $item;

            if (is_dir($path)) {
                $this->pruneDirectory($path, $rel, $validSet);
                $sub = @scandir($path);
                if ($sub !== false && count($sub) <= 2) {
                    @rmdir($path);
                }
            } elseif (is_file($path)) {
                if (!isset($validSet[$rel])) {
                    @unlink($path);
                }
            }
        }
    }

    private function shouldSkip(string $name): bool
    {
        if (in_array($name, self::APPLY_SKIP_DIRS, true) || in_array($name, self::APPLY_SKIP_FILES, true)) {
            return true;
        }
        foreach (self::APPLY_SKIP_PREFIXES as $prefix) {
            if (strpos($name, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    // ============================================================
    //  辅助方法
    // ============================================================

    private function rollbackInternal(string $backupDir): void
    {
        $abs = $backupDir;
        if (!is_dir($abs)) {
            $abs = UPDATE_BACKUPS_PATH . DIRECTORY_SEPARATOR . basename($backupDir);
        }
        if (!is_dir($abs)) {
            throw new \RuntimeException('Backup directory not found for rollback: ' . $backupDir);
        }

        $this->backup->restore($abs);

        // 回退版本号
        $restoredVersion = $this->versionFromBackupDir($abs);
        if ($restoredVersion !== '') {
            Manifest::setCurrentVersion($restoredVersion);
        }
    }

    /**
     * 从备份目录名 {timestamp}_{version} 解析版本号。
     */
    private function versionFromBackupDir(string $backupDir): string
    {
        $name = basename($backupDir);
        $pos  = strpos($name, '_');
        if ($pos === false) {
            return '';
        }
        return substr($name, $pos + 1);
    }

    private function writeFailedFlag(string $message): void
    {
        $flagFile = DATA_PATH . DIRECTORY_SEPARATOR . '.update_failed';
        $payload = json_encode([
            'error'     => $message,
            'timestamp' => time(),
            'phase'     => State::load()['phase'] ?? State::FAILED,
        ], JSON_UNESCAPED_UNICODE);
        @file_put_contents($flagFile, $payload, LOCK_EX);
    }

    private function loadConfig(): array
    {
        $file = ROOT_PATH . DIRECTORY_SEPARATOR . 'updater' . DIRECTORY_SEPARATOR . 'config.php';
        if (!is_file($file)) {
            return [
                'update_source_url'           => 'https://yoliarkupdate.yoliark.com/',
                'check_interval'              => 21600,
                'max_backups'                 => 3,
                'download_retry'              => 3,
                'download_timeout'            => 300,
                'worker_restart_poll_timeout' => 30,
                'health_check_http_timeout'   => 10,
            ];
        }
        $cfg = require $file;
        return is_array($cfg) ? $cfg : [];
    }
}
