<?php

namespace App\Controllers\Share;

use App\Controllers\BaseController;
use App\Core\Security;
use App\Core\Config;
use App\Services\ShareService;
use App\Support\HttpRangeTrait;

class SharePublicController extends BaseController
{
    use HttpRangeTrait;

    public function info()
    {
        $token = $this->input('token', '');
        if (empty($token)) {
            $this->renderShareError('分享链接无效');
        }

        $shareService = new ShareService();
        $shareInfo = $shareService->getShareInfo($token);

        if (!$shareInfo) {
            $this->renderShareError('分享链接无效或已过期');
        }

        $shareService->recordVisit($shareInfo['share']['id'], 'view');

        Security::jsonOutput([
            'success' => true,
            'file' => [
                'filename' => Security::escape($shareInfo['file']['filename']),
                'filesize' => $shareInfo['file']['filesize'],
                'filesize_formatted' => Security::formatSize($shareInfo['file']['filesize']),
                'file_type' => $shareInfo['file']['file_type'],
                'is_dir' => $shareInfo['file']['is_dir'],
            ],
            'has_password' => $shareInfo['has_password'],
            'share_token' => $shareInfo['share']['share_token'],
        ]);
    }

    public function download()
    {
        try {
            $token = $this->input('token', '');
            $password = $this->input('password', '');

            if (empty($token)) {
                $this->renderShareError('分享链接无效');
            }

            $shareService = new ShareService();
            $result = $shareService->downloadSharedFile($token, $password);

            if (!$result['success']) {
                $this->renderShareError($result['message'] ?? '下载失败');
            }

            $shareInfo = $shareService->getShareInfo($token);
            if ($shareInfo) {
                $shareService->recordVisit($shareInfo['share']['id'], 'download');
            }

            $fullPath = $result['path'];
            $filename = $result['filename'];
            $mimeType = $result['mime'];
            $fileSize = $result['size'];
            $isTemp = !empty($result['temp']);
            $contentHash = $result['content_hash'] ?? '';

            if (!file_exists($fullPath)) {
                $this->renderShareError('文件不存在或已被删除');
            }

            // 通过 Trait 统一处理 HTTP Range / 206 响应 / 临时文件清理 / exit
            $this->sendFileWithRange($fullPath, $filename, $fileSize, $mimeType, $contentHash, $isTemp);
        } catch (\Throwable $e) {
            $this->renderShareError('下载失败，请稍后重试');
        }
    }

    public function directAccess()
    {
        $token = $this->input('token', '');
        if (empty($token)) {
            $this->renderShareError('分享链接无效');
        }

        $shareService = new ShareService();
        $shareInfo = $shareService->getShareInfo($token);

        if (!$shareInfo) {
            $this->renderShareError('分享链接无效或已过期');
        }

        Security::jsonOutput([
            'success' => true,
            'file' => [
                'filename' => Security::escape($shareInfo['file']['filename']),
                'filesize' => $shareInfo['file']['filesize'],
                'filesize_formatted' => Security::formatSize($shareInfo['file']['filesize']),
                'file_type' => $shareInfo['file']['file_type'],
                'is_dir' => $shareInfo['file']['is_dir'],
            ],
            'has_password' => $shareInfo['has_password'],
            'share_token' => $shareInfo['share']['share_token'],
        ]);
    }

    public function recordShareVisit()
    {
        $token = $this->input('token', '');
        if (empty($token)) {
            Security::jsonOutput(['success' => false]);
        }

        $this->rateLimit('share_visit_' . $token, 1, 10);

        $shareService = new ShareService();
        $shareInfo = $shareService->getShareByToken($token);

        if ($shareInfo) {
            $shareService->recordVisit($shareInfo['id'], 'view');
        }

        Security::jsonOutput(['success' => true]);
    }

    /**
     * 渲染分享页面的错误 UI（复用 fluent-share 样式）。
     *
     * 所有分享相关的公开错误（链接无效、已过期、文件不存在等）
     * 统一通过此方法输出，避免向用户裸露 JSON 文本。
     * 输出自包含的 HTML 页面，复用分享页的 fluent-empty-state 组件。
     *
     * @param string $message 面向用户的错误描述
     * @param int $httpCode HTTP 状态码
     */
    private function renderShareError(string $message = '分享链接无效或已过期', int $httpCode = 0): void
    {
        // 使用 200 而非 404/410：nginx/apache 的 error_page 配置通常会拦截
        // 4xx/5xx 状态码并替换为 web server 自身的错误页，导致用户看到
        // 服务器默认 404 而非本方法输出的友好 UI。200 状态码不被拦截，
        // 页面内容本身已清晰表达了"链接无效"。
        http_response_code($httpCode > 0 ? $httpCode : 200);

        $appName = Security::escape(Config::getInstance()->get('app_name', '柚舟Cloud'));
        $safeMessage = Security::escape($message);

        echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title>{$appName} - 文件分享</title>
    <link rel="stylesheet" href="assets/css/fluent-share.css">
    <link rel="stylesheet" href="assets/css/base.css">
    <link rel="stylesheet" href="assets/css/dark-theme.css">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
</head>
<body>
<div class="fluent-share-page">
    <div class="fluent-share-container">
        <div class="fluent-share-card">
            <div class="fluent-share-header">
                <div class="fluent-share-icon"><i class="fas fa-link"></i></div>
                <h1 class="fluent-share-title">文件分享</h1>
                <p class="fluent-share-subtitle">链接无效或已过期</p>
            </div>
            <div class="fluent-empty-state">
                <div class="fluent-empty-icon"><i class="fas fa-circle-exclamation"></i></div>
                <h3 class="fluent-empty-title">链接无效</h3>
                <p class="fluent-empty-desc">{$safeMessage}</p>
            </div>
        </div>
    </div>
</div>
</body>
</html>
HTML;
        exit;
    }
}
