<?php
use App\Core\Security;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, maximum-scale=1.0, user-scalable=no">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="googlebot" content="noindex, nofollow">
    <meta name="bingbot" content="noindex, nofollow">
    <meta name="revisit-after" content="never">
    <meta name="app-build-hash" content="<?php echo $pageBuildHash; ?>">
    <title><?php echo Security::escape($config->get('app_name')); ?> - 个人网盘</title>
    <link rel="stylesheet" href="assets/css/base.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/layout.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/components.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/login.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/share-page.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/file-list.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/share-list.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/logs.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/trash.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/settings.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/dialogs.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/preview.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/preview-mobile.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/responsive.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/dark-theme.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/ai-chat.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/mobile.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/fluent-share.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/inbox.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/ad.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css?v=<?php echo $pageBuildHash; ?>">
    <script src="https://cdn.staticfile.org/dompurify/3.0.6/purify.min.js"></script>
    <script>
        // DOMPurify CDN 加载失败时回退到本地
        if (typeof DOMPurify === 'undefined') {
            document.write('<script src="assets/vendor/purify.min.js?v=<?php echo $pageBuildHash; ?>"><\/script>');
        }
    </script>
    <script src="assets/js/jsmediatags.min.js?v=<?php echo $pageBuildHash; ?>"></script>
</head>
<body>

<div class="bg-mesh">
    <div class="orb"></div>
    <div class="orb"></div>
</div>
