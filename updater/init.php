<?php

/**
 * 更新子系统 v2 引导文件。
 *
 * 由 UpdateController require，假定 DATA_PATH / ROOT_PATH / PANCLOUD_VERSION 已由主程序定义。
 * 不做 CLI 守卫，FPM 与 CLI 均可加载。
 */

if (defined('UPDATER_INIT_LOADED')) {
    return;
}
define('UPDATER_INIT_LOADED', true);

define('UPDATES_PATH', DATA_PATH . DIRECTORY_SEPARATOR . '.updates');
define('UPDATE_STAGING_PATH', UPDATES_PATH . DIRECTORY_SEPARATOR . 'staging');
define('UPDATE_BACKUPS_PATH', UPDATES_PATH . DIRECTORY_SEPARATOR . 'backups');

spl_autoload_register(function ($class) {
    $prefix = 'Updater\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative = substr($class, $len);
    $file = ROOT_PATH . DIRECTORY_SEPARATOR . 'updater' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR
        . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

foreach ([UPDATES_PATH, UPDATE_STAGING_PATH, UPDATE_BACKUPS_PATH] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}
