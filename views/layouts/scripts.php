<script src="assets/js/store.js?v=<?php echo $pageBuildHash; ?>"></script>
<script src="assets/js/utils.js?v=<?php echo $pageBuildHash; ?>"></script>
<script src="assets/js/core.js?v=<?php echo $pageBuildHash; ?>"></script>
<script src="assets/js/theme.js?v=<?php echo $pageBuildHash; ?>"></script>
<script src="assets/js/upload.js?v=<?php echo $pageBuildHash; ?>"></script>
<script src="assets/js/preview.js?v=<?php echo $pageBuildHash; ?>"></script>
<script src="assets/js/preview-mobile.js?v=<?php echo $pageBuildHash; ?>"></script>
<script src="assets/js/update.js?v=<?php echo $pageBuildHash; ?>"></script>
<script src="assets/js/files.js?v=<?php echo $pageBuildHash; ?>"></script>
<script src="assets/js/ai.js?v=<?php echo $pageBuildHash; ?>"></script>
<script src="assets/js/share.js?v=<?php echo $pageBuildHash; ?>"></script>
<script src="assets/js/pages.js?v=<?php echo $pageBuildHash; ?>"></script>
<script>
window.APP_CONFIG = {
    debug: <?php echo $config->get('debug') ? 'true' : 'false'; ?>,
    csrfToken: <?php echo json_encode($csrfToken); ?>,
    chunkSize: <?php echo (int)$config->get('chunk_size'); ?>
};

// PWA：注册 Service Worker
// 附加 sw.js 文件 mtime 作为版本查询参数：每次部署后浏览器立即感知 SW 变更，
// 同时 SW 内部从 ?v= 读取并刷新 CACHE_NAME，触发 activate 阶段清理所有旧缓存。
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        var swPath = 'sw.js?v=<?php echo filemtime(__DIR__ . '/../../sw.js'); ?>';
        navigator.serviceWorker.register(swPath).then(function (reg) {
            reg.onupdatefound = function () {
                var installing = reg.installing;
                if (installing) {
                    installing.onstatechange = function () {
                        if (installing.state === 'installed' && navigator.serviceWorker.controller) {
                            console.log('[PWA] 新版本已缓存，下次加载时生效');
                        }
                    };
                }
            };
        }).catch(function (err) {
            console.warn('[PWA] Service Worker 注册失败:', err);
        });
    });
    
    // 拦截「添加到主屏幕」弹窗提示
    var deferredPrompt = null;
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        // 可以在这里延时弹出安装提示，暂不自动触发
    });
    
    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        console.log('[PWA] 已安装到主屏幕');
    });
}
</script>

