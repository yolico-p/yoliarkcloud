<?php

/**
 * 更新子系统默认配置。
 *
 * 由 Updater\Updater::loadConfig() 加载，可在运行时被 Manifest 中的覆盖配置合并。
 */

return [
    // 官方更新源（硬编码，前端不可修改）
    'update_source_url' => 'https://yoliarkupdate.yoliark.com/',

    // 版本检查最小间隔（秒）—— 6 小时
    'check_interval' => 21600,

    // 保留备份份数
    'max_backups' => 3,

    // 下载失败重试次数
    'download_retry' => 3,

    // 下载单次超时（秒）
    'download_timeout' => 300,

    // 等待 Worker 心跳消失的最大轮询时间（秒）
    'worker_restart_poll_timeout' => 30,

    // 健康检查 HTTP 自探超时（秒）
    'health_check_http_timeout' => 10,
];
