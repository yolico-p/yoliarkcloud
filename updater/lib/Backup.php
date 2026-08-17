<?php

namespace Updater;

/**
 * 备份管理器：将 ROOT_PATH 复制到 UPDATE_BACKUPS_PATH/{timestamp}_{version}/。
 *
 * 排除动态目录：storage/uploads/updater/.git/.trae/node_modules/
 * 与敏感文件：config.json、.maintenance、.worker_*
 *
 * 注意：vendor（composer 依赖）属于代码的一部分，必须包含在备份中，
 * 否则回滚后依赖版本与代码不匹配会导致运行时错误。
 */
class Backup
{
    /** 排除目录名（相对 ROOT_PATH） */
    private const EXCLUDED_DIRS = [
        'storage',
        'uploads',
        'updater',
        '.git',
        '.trae',
        'node_modules',
    ];

    /** 排除文件名（精确匹配） */
    private const EXCLUDED_FILES = [
        'config.json',
        '.maintenance',
    ];

    /** 排除文件前缀 */
    private const EXCLUDED_PREFIXES = [
        '.worker_',
    ];

    /**
     * 创建备份。返回备份目录绝对路径。
     *
     * @throws \RuntimeException 备份失败
     */
    public function create(): string
    {
        $version = defined('PANCLOUD_VERSION') ? PANCLOUD_VERSION : 'unknown';
        $dirName = time() . '_' . $this->sanitizeVersion($version);
        $backupDir = UPDATE_BACKUPS_PATH . DIRECTORY_SEPARATOR . $dirName;

        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }

        $this->copyDirectory(ROOT_PATH, $backupDir);

        return $backupDir;
    }

    /**
     * 从备份恢复到 ROOT_PATH。
     *
     * @throws \RuntimeException 恢复失败
     */
    public function restore(string $backupDir): void
    {
        if (!is_dir($backupDir)) {
            throw new \RuntimeException('Backup directory not found: ' . $backupDir);
        }

        $this->copyDirectory($backupDir, ROOT_PATH);
    }

    /**
     * 清理超限备份。按 createdAt 排序保留最新 $maxBackups 个。
     */
    public function prune(int $maxBackups): int
    {
        if (!is_dir(UPDATE_BACKUPS_PATH)) {
            return 0;
        }

        $backups = [];
        foreach (glob(UPDATE_BACKUPS_PATH . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $dir) {
            $backups[] = [
                'dir'  => $dir,
                'time' => filemtime($dir) ?: 0,
            ];
        }

        if (count($backups) <= $maxBackups) {
            return 0;
        }

        usort($backups, fn($a, $b) => $a['time'] <=> $b['time']);
        $toDelete = array_slice($backups, 0, count($backups) - $maxBackups);

        $deleted = 0;
        foreach ($toDelete as $b) {
            if ($this->removeDirectory($b['dir'])) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * 递归复制目录（应用排除规则）。
     */
    private function copyDirectory(string $src, string $dst): void
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
            if ($this->isExcluded($item)) {
                continue;
            }

            $srcPath = $src . DIRECTORY_SEPARATOR . $item;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $item;

            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $dstPath);
            } elseif (is_file($srcPath)) {
                $this->copyFilePreserve($srcPath, $dstPath);
            }
        }
    }

    /**
     * 复制单个文件并保留源文件权限位。
     */
    private function copyFilePreserve(string $src, string $dst): void
    {
        if (!@copy($src, $dst)) {
            return;
        }
        $perm = @fileperms($src);
        if ($perm !== false) {
            @chmod($dst, $perm & 0777);
        }
    }

    private function isExcluded(string $name): bool
    {
        if (in_array($name, self::EXCLUDED_DIRS, true) || in_array($name, self::EXCLUDED_FILES, true)) {
            return true;
        }
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (strpos($name, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    private function removeDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }
        return @rmdir($dir);
    }

    /**
     * 计算目录大小（字节）。
     */
    public static function dirSize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $size = 0;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($items as $item) {
            if ($item->isFile()) {
                $size += $item->getSize();
            }
        }
        return $size;
    }

    private function sanitizeVersion(string $version): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $version) ?: 'unknown';
    }
}
