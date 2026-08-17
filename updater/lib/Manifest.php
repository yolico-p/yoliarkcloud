<?php

namespace Updater;

/**
 * 本地清单：当前版本、实例 ID、待更新信息、备份列表、历史、自动更新配置。
 *
 * 持久化到 UPDATES_PATH/manifest.json。
 */
class Manifest
{
    private static ?array $cache = null;

    private static function path(): string
    {
        return UPDATES_PATH . DIRECTORY_SEPARATOR . 'manifest.json';
    }

    public static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $file = self::path();
        if (!is_file($file)) {
            return self::$cache = self::defaultData();
        }

        $fp = @fopen($file, 'r');
        if (!$fp) {
            return self::$cache = self::defaultData();
        }

        $locked = @flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        if ($locked) {
            flock($fp, LOCK_UN);
        }
        fclose($fp);

        $data = $content !== false ? json_decode($content, true) : null;
        if (!is_array($data)) {
            return self::$cache = self::defaultData();
        }

        return self::$cache = array_merge(self::defaultData(), $data);
    }

    public static function save(array $data): bool
    {
        $file = self::path();
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0755, true);
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }

        // 原子写入：临时文件 + flock + rename，避免崩溃时 manifest.json 损坏
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

        self::$cache = $data;
        return true;
    }

    public static function getCurrentVersion(): string
    {
        return self::load()['currentVersion'] ?? '0.0.0';
    }

    public static function setCurrentVersion(string $version): bool
    {
        $data = self::load();
        $data['currentVersion'] = $version;
        return self::save($data);
    }

    public static function getInstanceId(): string
    {
        $data = self::load();
        if (empty($data['instanceId'])) {
            $data['instanceId'] = self::uuidV4();
            self::save($data);
        }
        return $data['instanceId'];
    }

    public static function getLastCheck(): int
    {
        return (int)(self::load()['lastCheck'] ?? 0);
    }

    public static function setLastCheck(int $timestamp): bool
    {
        $data = self::load();
        $data['lastCheck'] = $timestamp;
        return self::save($data);
    }

    /**
     * 上次成功验证的服务端 manifest generatedAt（防重放基准）。
     */
    public static function getLastManifestGeneratedAt(): int
    {
        return (int)(self::load()['lastManifestGeneratedAt'] ?? 0);
    }

    public static function setLastManifestGeneratedAt(int $timestamp): bool
    {
        $data = self::load();
        $data['lastManifestGeneratedAt'] = $timestamp;
        return self::save($data);
    }

    public static function getPendingUpdate(): ?array
    {
        $v = self::load()['pendingUpdate'] ?? null;
        return is_array($v) ? $v : null;
    }

    public static function setPendingUpdate(?array $info): bool
    {
        $data = self::load();
        $data['pendingUpdate'] = $info;
        return self::save($data);
    }

    public static function getNewFeatures(): array
    {
        return self::load()['newFeatures'] ?? [];
    }

    public static function setNewFeatures(array $features): bool
    {
        $data = self::load();
        $data['newFeatures'] = array_values($features);
        return self::save($data);
    }

    public static function getBackups(): array
    {
        return self::load()['backups'] ?? [];
    }

    public static function addBackup(array $backup): bool
    {
        $data = self::load();
        $data['backups'][] = $backup;
        return self::save($data);
    }

    public static function pruneBackups(int $maxBackups): bool
    {
        $data = self::load();
        $backups = $data['backups'] ?? [];
        if (count($backups) <= $maxBackups) {
            return true;
        }
        usort($backups, function ($a, $b) {
            return ($a['createdAt'] ?? 0) <=> ($b['createdAt'] ?? 0);
        });
        $data['backups'] = array_values(array_slice($backups, count($backups) - $maxBackups));
        return self::save($data);
    }

    public static function removeBackup(string $dir): bool
    {
        $data = self::load();
        $data['backups'] = array_values(array_filter(
            $data['backups'] ?? [],
            fn($b) => ($b['dir'] ?? '') !== $dir
        ));
        return self::save($data);
    }

    public static function getHistory(): array
    {
        return self::load()['history'] ?? [];
    }

    public static function addHistory(array $entry): bool
    {
        $data = self::load();
        $data['history'][] = $entry;
        // 仅保留最近 50 条
        if (count($data['history']) > 50) {
            $data['history'] = array_values(array_slice($data['history'], -50));
        }
        return self::save($data);
    }

    public static function getAutoUpdateConfig(): array
    {
        return self::load()['autoUpdateConfig'] ?? self::defaultAutoUpdateConfig();
    }

    public static function setAutoUpdateConfig(array $config): bool
    {
        $data = self::load();
        $data['autoUpdateConfig'] = array_merge(self::defaultAutoUpdateConfig(), $config);
        return self::save($data);
    }

    private static function defaultData(): array
    {
        return [
            'currentVersion'        => defined('PANCLOUD_VERSION') ? PANCLOUD_VERSION : '0.0.0',
            'instanceId'            => '',
            'lastCheck'             => 0,
            'lastManifestGeneratedAt' => 0,
            'pendingUpdate'         => null,
            'newFeatures'           => [],
            'backups'               => [],
            'history'               => [],
            'autoUpdateConfig'      => self::defaultAutoUpdateConfig(),
        ];
    }

    private static function defaultAutoUpdateConfig(): array
    {
        return [
            'enabled'       => false,
            'channel'       => 'stable',
            'checkInterval' => 21600,
            'strategy'      => 'notify_only',
        ];
    }

    private static function uuidV4(): string
    {
        $bytes = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
