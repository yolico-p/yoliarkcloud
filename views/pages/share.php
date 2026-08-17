<?php
use App\Core\Security;

$fileTypeIcons = [
    'folder' => 'fa-folder',
    'jpg' => 'fa-file-image', 'jpeg' => 'fa-file-image', 'png' => 'fa-file-image', 'gif' => 'fa-file-image',
    'bmp' => 'fa-file-image', 'webp' => 'fa-file-image', 'svg' => 'fa-file-image', 'ico' => 'fa-file-image',
    'mp4' => 'fa-file-video', 'avi' => 'fa-file-video', 'mkv' => 'fa-file-video', 'mov' => 'fa-file-video',
    'webm' => 'fa-file-video', 'wmv' => 'fa-file-video', 'flv' => 'fa-file-video',
    'mp3' => 'fa-file-audio', 'wav' => 'fa-file-audio', 'flac' => 'fa-file-audio', 'ogg' => 'fa-file-audio',
    'aac' => 'fa-file-audio', 'm4a' => 'fa-file-audio',
    'pdf' => 'fa-file-pdf',
    'doc' => 'fa-file-word', 'docx' => 'fa-file-word',
    'xls' => 'fa-file-excel', 'xlsx' => 'fa-file-excel',
    'ppt' => 'fa-file-powerpoint', 'pptx' => 'fa-file-powerpoint',
    'zip' => 'fa-file-archive', 'rar' => 'fa-file-archive', '7z' => 'fa-file-archive',
    'txt' => 'fa-file-lines', 'md' => 'fa-file-lines',
    'json' => 'fa-file-code', 'xml' => 'fa-file-code', 'html' => 'fa-file-code', 'js' => 'fa-file-code',
    'css' => 'fa-file-code', 'php' => 'fa-file-code', 'py' => 'fa-file-code',
];

$ext = strtolower(pathinfo($shareData['filename'] ?? '', PATHINFO_EXTENSION));
$fileIcon = $fileTypeIcons[$ext] ?? 'fa-file';

// 广告数据
$adAds = [];
$adEnabled = false;
try {
    $adService = new \App\Services\AdService();
    if ($adService->isEnabled()) {
        $adAds = $adService->getAds();
    }
} catch (\Throwable $e) {
    $adAds = [];
}

?>
<div class="fluent-share-page">
    <div class="fluent-share-container">
        <div class="fluent-share-card">
            <div class="fluent-share-header">
                <div class="fluent-share-icon"><i class="fas fa-link"></i></div>
                <h1 class="fluent-share-title">文件分享</h1>
                <p class="fluent-share-subtitle"><?php echo isset($shareData) ? '文件已就绪，点击下载' : '链接无效或已过期'; ?></p>
            </div>
            <?php if (isset($shareData)): ?>
                <div class="fluent-file-preview">
                    <div class="fluent-file-icon-wrap">
                        <i class="fas <?php echo $fileIcon; ?>"></i>
                    </div>
                    <div class="fluent-file-info">
                        <div class="fluent-file-name"><?php echo Security::escape($shareData['filename']); ?></div>
                        <div class="fluent-file-meta">
                            <span class="fluent-file-size"><?php echo Security::escape($shareData['filesize_formatted']); ?></span>
                            <span class="fluent-file-ext"><?php echo strtoupper($ext ?: '?'); ?></span>
                        </div>
                    </div>
                </div>

                <?php if ($shareData['has_password']): ?>
                <form id="sharePasswordForm" class="fluent-form">
                    <div class="fluent-form-group">
                        <label for="sharePassword" class="fluent-form-label">提取密码</label>
                        <div class="fluent-input-wrap">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="sharePassword" name="password" class="fluent-input" required placeholder="请输入提取密码">
                        </div>
                    </div>
                    <div id="shareError" class="fluent-error-message"></div>
                    <button type="submit" class="fluent-btn fluent-btn-primary fluent-btn-block">
                        <i class="fas fa-download"></i> 下载文件
                    </button>
                </form>
                <?php else: ?>
                <button class="fluent-btn fluent-btn-primary fluent-btn-block" data-action="start-download" data-token="<?php echo Security::escape($token); ?>">
                    <i class="fas fa-download"></i> 下载文件
                </button>
                <?php endif; ?>
                <div class="fluent-share-footer">
                    <span>由 柚舟Cloud 安全分享</span>
                </div>
            <?php else: ?>
                <div class="fluent-empty-state">
                    <div class="fluent-empty-icon"><i class="fas fa-circle-exclamation"></i></div>
                    <h3 class="fluent-empty-title">链接无效</h3>
                    <p class="fluent-empty-desc">该分享链接不存在或已过期</p>
                </div>
            <?php endif; ?>
        </div>
<?php if (!empty($adAds) && isset($shareData)): ?>
<div class="fluent-ad-section" id="shareAdSection">
    <div class="fluent-ad-header">
        <span class="fluent-ad-label">推广</span>
    </div>
    <div class="fluent-ad-grid fluent-ad-count-<?php echo count($adAds); ?>">
        <?php foreach ($adAds as $ad): ?>
        <a href="<?php echo Security::escape($ad['link']); ?>" target="_blank" rel="noopener noreferrer sponsored nofollow" class="fluent-ad-card">
            <img src="<?php echo Security::escape($ad['img']); ?>" alt="" loading="lazy">
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
    </div>
</div>
<?php if (isset($shareData)): ?>
<script>
var shareCsrfToken = <?php echo json_encode($csrfToken ?? ''); ?>;
fetch('index.php?action=record_share_visit', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':shareCsrfToken},body:'token=<?php echo htmlspecialchars($token, ENT_QUOTES); ?>&visit_type=view&_csrf_token=' + encodeURIComponent(shareCsrfToken)}).catch(function(){});

var urlParams = new URLSearchParams(window.location.search);
if (urlParams.get('download') === '1') {
    var password = urlParams.get('password') || '';
    setTimeout(function() { startDownload('<?php echo htmlspecialchars($token, ENT_QUOTES); ?>', password); }, 500);
}

(function() {
    var shareCard = document.querySelector('.fluent-share-card');
    if (shareCard) {
        shareCard.addEventListener('click', function(e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) return;
            var action = btn.dataset.action;
            if (action === 'start-download') startDownload(btn.dataset.token, '');
        });
    }

    var pwdForm = document.getElementById('sharePasswordForm');
    if (pwdForm) {
        pwdForm.addEventListener('submit', function(e) {
            handleSharePasswordSubmit(e);
        });
    }
})();
</script>
<?php endif; ?>
</body>
</html>
