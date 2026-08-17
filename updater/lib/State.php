<?php

namespace Updater;

/**
 * 更新流程状态机。
 *
 * 持久化到 UPDATES_PATH/state.json，所有读写使用 flock 保护。
 */
class State
{
    public const IDLE                  = 'idle';
    public const CHECKING              = 'checking';
    public const DOWNLOADING           = 'downloading';
    public const VERIFYING             = 'verifying';
    public const MAINTENANCE_ON        = 'maintenance_on';
    public const STOPPING_WORKER       = 'stopping_worker';
    public const BACKING_UP            = 'backing_up';
    public const APPLYING              = 'applying';
    public const RESTARTING            = 'restarting';
    public const HEALTH_CHECK          = 'health_check';
    public const ROLLING_BACK          = 'rolling_back';
    public const COMPLETED             = 'completed';
    public const COMPLETED_ROLLED_BACK = 'completed_rolled_back';
    public const FAILED                = 'failed';

    private const TERMINAL_PHASES = [
        self::IDLE, self::COMPLETED, self::COMPLETED_ROLLED_BACK, self::FAILED,
    ];

    private static ?array $cache = null;

    private static function path(): string
    {
        return UPDATES_PATH . DIRECTORY_SEPARATOR . 'state.json';
    }

    public static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $file = self::path();
        if (!is_file($file)) {
            return self::$cache = self::defaultState();
        }

        $fp = @fopen($file, 'r');
        if (!$fp) {
            return self::$cache = self::defaultState();
        }

        $locked = @flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        if ($locked) {
            flock($fp, LOCK_UN);
        }
        fclose($fp);

        $data = $content !== false ? json_decode($content, true) : null;
        if (!is_array($data)) {
            return self::$cache = self::defaultState();
        }

        return self::$cache = array_merge(self::defaultState(), $data);
    }

    public static function save(array $state): bool
    {
        $file = self::path();
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0755, true);
        }

        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }

        // 原子写入：临时文件 + flock + rename，避免崩溃时 state.json 损坏
        $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }

        $fp = @fopen($file, 'c+');
        if (!$fp) {
            @unlink($tmp);
            return false;
        }

        flock($fp, LOCK_EX);
        $ok = @rename($tmp, $file);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!$ok) {
            @unlink($tmp);
            return false;
        }

        self::$cache = $state;
        return true;
    }

    public static function setPhase(string $phase, array $data = []): bool
    {
        $state = self::load();
        $state['phase'] = $phase;
        $state['updated_at'] = time();
        foreach ($data as $k => $v) {
            $state[$k] = $v;
        }
        return self::save($state);
    }

    public static function setProgress(int $percent, string $message = ''): bool
    {
        $state = self::load();
        $state['progress'] = max(0, min(100, $percent));
        $state['message']  = $message;
        $state['updated_at'] = time();
        return self::save($state);
    }

    public static function isInProgress(): bool
    {
        $state = self::load();
        return !in_array($state['phase'] ?? self::IDLE, self::TERMINAL_PHASES, true);
    }

    public static function reset(): bool
    {
        return self::save(self::defaultState());
    }

    private static function defaultState(): array
    {
        return [
            'phase' => self::IDLE,
            'progress' => 0,
            'message' => '',
            'updated_at' => 0,
            'error' => null,
        ];
    }
}
