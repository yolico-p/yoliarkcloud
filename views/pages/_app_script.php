<?php
// 应用页面初始化脚本已抽取为外部 JS：assets/js/batch_rename.js
// 优点：可被 Service Worker 预缓存、可被 lint 静态分析、颜色统一走 CSS 变量
// 附加 mtime 作为版本号：部署后浏览器立即拉取新版本，避免缓存陈旧
$batchRenameJs = __DIR__ . '/../../assets/js/batch_rename.js';
$mobileJs = __DIR__ . '/../../assets/js/mobile.js';
?>
<script src="assets/js/batch_rename.js?v=<?php echo filemtime($batchRenameJs); ?>"></script>
<script src="assets/js/mobile.js?v=<?php echo filemtime($mobileJs); ?>"></script>
