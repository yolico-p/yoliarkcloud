<?php

namespace Updater;

/**
 * 维护模式：通过 ROOT_PATH/.maintenance 文件标记。
 */
class Maintenance
{
    private static function path(): string
    {
        return ROOT_PATH . DIRECTORY_SEPARATOR . '.maintenance';
    }

    /**
     * 启用维护模式。
     */
    public static function enable(array $data = []): bool
    {
        $payload = array_merge([
            'reason'             => 'system_update',
            'timestamp'          => time(),
            'progress_endpoint'  => 'api.php?action=get_update_status',
        ], $data);

        return @file_put_contents(
            self::path(),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        ) !== false;
    }

    /**
     * 关闭维护模式。
     */
    public static function disable(): bool
    {
        if (!is_file(self::path())) {
            return true;
        }
        return @unlink(self::path());
    }

    /**
     * 是否处于维护模式。
     */
    public static function isActive(): bool
    {
        return is_file(self::path());
    }

    /**
     * 渲染维护页 HTML。
     */
    public static function renderMaintenancePage(): string
    {
        $data = [];
        if (is_file(self::path())) {
            $decoded = json_decode((string)file_get_contents(self::path()), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $reason = htmlspecialchars((string)($data['reason'] ?? 'system_update'), ENT_QUOTES, 'UTF-8');
        $startedAt = (int)($data['timestamp'] ?? time());

        return '<!DOCTYPE html><html lang="zh-CN"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>系统维护中 - YoliArkCloud</title>'
            . '<style>'
            . '*{margin:0;padding:0;box-sizing:border-box}'
            . 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
            . 'display:flex;align-items:center;justify-content:center;min-height:100vh;'
            . 'background:#f5f5f5;color:#333}'
            . '.container{text-align:center;padding:40px;background:#fff;border-radius:12px;'
            . 'box-shadow:0 2px 12px rgba(0,0,0,.08);max-width:420px;width:90%}'
            . '.spinner{width:40px;height:40px;border:3px solid #e5e5e5;border-top-color:#007DFF;'
            . 'border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 20px}'
            . '@keyframes spin{to{transform:rotate(360deg)}}'
            . 'h2{font-size:18px;margin-bottom:8px;font-weight:500}'
            . 'p{font-size:14px;color:#666;line-height:1.6}'
            . '.hint{margin-top:16px;font-size:12px;color:#999}'
            . '</style></head><body><div class="container">'
            . '<div class="spinner"></div>'
            . '<h2>系统维护中</h2>'
            . '<p>系统正在进行更新，请稍候片刻。</p>'
            . '<p class="hint">原因: ' . $reason . ' · 开始于 ' . date('Y-m-d H:i:s', $startedAt) . '</p>'
            . '</div></body></html>';
    }
}
