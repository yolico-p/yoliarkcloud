<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Core\Config;
use App\Core\ConcurrencyGuard;
use App\Support\LogHelper;

class ShareService
{
    use LogHelper;
    private $db;
    private $auth;
    private $config;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->auth = new AuthService();
        $this->config = Config::getInstance();
    }

    /**
     * 生成真正的 QR 码（PNG，base64 编码）。
     *
     * 优先使用 composer 依赖 chillerlan/php-qrcode 生成可被扫描器识别的二维码；
     * 若依赖未安装（vendor 缺失），返回空字符串，此时由前端使用
     * qrcode.js 等 CDN 库根据分享 URL 自行渲染二维码。
     */
    private function generateQRCodeSVG($data)
    {
        // 依赖已安装时，生成真正的 QR 编码 PNG
        if (class_exists(\chillerlan\QRCode\QRCode::class)) {
            try {
                $qr = new \chillerlan\QRCode\QRCode();
                $pngData = $qr->render($data);
                return base64_encode($pngData);
            } catch (\Throwable $e) {
                return '';
            }
        }

        // 依赖不可用：不再生成无法扫描的伪二维码，交由前端渲染
        return '';
    }

    public function createShare($fileId, $options = [])
    {
        $userId = $this->auth->getUserId();
        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);

        if (!$file) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        $password = isset($options['password']) && !empty($options['password']) ? Security::hashPassword($options['password']) : '';
        $maxDownloads = intval($options['max_downloads'] ?? 0);
        $expireAt = 0;

        if (isset($options['expire_days'])) {
            $expireDays = intval($options['expire_days']);
            if ($expireDays > 0) {
                $expireAt = time() + ($expireDays * 24 * 3600);
            } else {
                $expireAt = 0;
            }
        } elseif ($this->config->get('share_default_expire') > 0) {
            $expireAt = time() + $this->config->get('share_default_expire');
        }

        // 生成 token 并处理碰撞：捕获 UNIQUE 约束冲突后重新生成，最多重试 5 次
        $token = '';
        $now = time();
        $maxAttempts = 5;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $token = Security::generateToken($this->config->get('share_link_length'));
            try {
                $this->db->insert('shares', [
                    'user_id' => $userId,
                    'file_id' => $fileId,
                    'share_token' => $token,
                    'share_password' => $password,
                    'download_count' => 0,
                    'max_downloads' => $maxDownloads,
                    'expire_at' => $expireAt,
                    'created_at' => $now,
                    'is_active' => 1,
                ]);
                break;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $isUniqueViolation = ($e instanceof \PDOException && ($e->getCode() === '23000' || $e->getCode() === '23001'))
                    || stripos($msg, 'unique') !== false
                    || stripos($msg, 'constraint') !== false
                    || stripos($msg, 'duplicate') !== false;
                if (!$isUniqueViolation || $attempt === $maxAttempts - 1) {
                    throw $e;
                }
                // 碰撞则换 token 重试
            }
        }
        if ($token === '') {
            throw new \RuntimeException('分享 token 生成失败');
        }

        $shareUrl = $this->getShareUrl($token);

        $this->logOperation('create_share', $file['filename']);

        return [
            'success' => true,
            'message' => '分享链接已创建',
            'share_token' => $token,
            'share_url' => $shareUrl,
            'expire_at' => $expireAt,
            'has_password' => !empty($password),
        ];
    }

    /**
     * 创建分享（QR 码由前端 QRCode.js 库生成，后端不生成装饰性伪二维码）
     */
    public function createShareWithQRCode($fileId, $options = [])
    {
        return $this->createShare($fileId, $options);
    }

    public function getShareByToken($token)
    {
        // 原子条件 UPDATE：单条语句完成"过期/达上限检查 + 停用"
        // 消除原 SELECT 后 UPDATE 之间的 TOCTOU 窗口
        $this->db->query(
            "UPDATE shares SET is_active = 0
             WHERE share_token = ?
               AND is_active = 1
               AND (
                   (expire_at > 0 AND expire_at < ?)
                   OR (max_downloads > 0 AND download_count >= max_downloads)
               )",
            [$token, time()]
        );

        $share = $this->db->fetch(
            "SELECT * FROM shares WHERE share_token = ? AND is_active = 1",
            [$token]
        );

        return $share ?: null;
    }

    /**
     * 仅做存在性与密码校验，不做下载计数。
     * 计数递增由 downloadSharedFile 通过 incrementWithLimit 原子完成，
     * 并强制消费其返回值，杜绝 max_downloads 被并发突破。
     */
    public function checkShareAccessible($token, $password = '')
    {
        $share = $this->getShareByToken($token);
        if (!$share) {
            return ['ok' => false, 'message' => '分享链接无效或已过期'];
        }

        if (!empty($share['share_password'])) {
            if (empty($password)) {
                return ['ok' => false, 'message' => '需要提取密码', 'need_password' => true, 'share' => $share];
            }
            if (!Security::verifyPassword($password, $share['share_password'])) {
                return ['ok' => false, 'message' => '提取码错误', 'need_password' => true, 'share' => $share];
            }
        }

        return ['ok' => true, 'share' => $share];
    }

    public function getShareInfo($token)
    {
        $share = $this->getShareByToken($token);
        if (!$share) {
            return null;
        }

        // 文件信息读多写少，走 fetchCached（shares/files 表变更时自动失效）
        $file = $this->db->fetchOneCached(
            "SELECT * FROM files WHERE id = ?",
            [$share['file_id']],
            ['files', 'shares']
        );
        if (!$file) {
            return null;
        }

        if ($file['is_dir']) {
            // 复用 FileManagerService 的 CTE 版本，避免 N+1 递归查询
            $totalSize = (new FileManagerService())->calculateFolderSize($file['id'], $file['user_id']);
            $file['filesize'] = $totalSize;
        }

        return [
            'share' => $share,
            'file' => $file,
            'has_password' => !empty($share['share_password']),
        ];
    }

    public function verifySharePassword($shareId, $password)
    {
        // 分享行读多写少，走 fetchOneCached（shares 表变更时自动失效）
        $share = $this->db->fetchOneCached(
            "SELECT * FROM shares WHERE id = ?",
            [$shareId],
            ['shares']
        );

        if (!$share || empty($share['share_password'])) {
            return true;
        }

        return Security::verifyPassword($password, $share['share_password']);
    }

    public function downloadSharedFile($token, $password = '')
    {
        $check = $this->checkShareAccessible($token, $password);
        if (!$check['ok']) {
            // 需要密码时仍要返回 share id 给前端
            $ret = ['success' => false, 'message' => $check['message']];
            if (!empty($check['need_password'])) $ret['need_password'] = true;
            return $ret;
        }
        $share = $check['share'];

        $file = $this->db->fetch("SELECT * FROM files WHERE id = ?", [$share['file_id']]);

        if (!$file) {
            return ['success' => false, 'message' => '文件不存在或已被删除'];
        }

        $isFolder = (bool)$file['is_dir'];
        $maxDownloads = (int)$share['max_downloads'];

        // ── 关键修复：先消费 incrementWithLimit 的原子结果，再执行下载 ──
        // 修复前：incrementWithLimit 返回 ['incremented' => false] 时仍 return success
        //         导致 max_downloads 在并发下被突破
        $incResult = ConcurrencyGuard::getInstance()->incrementWithLimit(
            'shares', 'download_count', 'id = ? AND is_active = 1', [$share['id']],
            $maxDownloads, 'is_active'
        );

        if (empty($incResult['incremented'])) {
            // 已达上限或分享被并发停用，拒绝下载
            return ['success' => false, 'message' => '分享已达下载次数上限或已失效'];
        }

        if ($isFolder) {
            $result = $this->downloadSharedFolder($file);
            // 文件夹打包失败时回滚已递增的计数，避免无谓消耗配额
            // 使用 CASE WHEN 替代 GREATEST()：SQLite 不支持 GREATEST 标量函数，
            // CASE WHEN 是标准 SQL，三种数据库（SQLite/MySQL/PostgreSQL）均兼容
            if (empty($result['success'])) {
                $this->db->query(
                    "UPDATE shares SET download_count = CASE WHEN download_count > 0 THEN download_count - 1 ELSE 0 END WHERE id = ?",
                    [$share['id']]
                );
            }
            return $result;
        }

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];

        if (!Security::isSafeFilePath($fullPath)) {
            return ['success' => false, 'message' => '文件路径异常'];
        }

        if (!file_exists($fullPath)) {
            return ['success' => false, 'message' => '文件已被删除'];
        }

        // 直接返回原文件，不添加水印
        return [
            'success' => true,
            'path' => $fullPath,
            'filename' => $file['filename'],
            'mime' => $file['mime_type'],
            'size' => $file['filesize'],
            'content_hash' => $file['content_hash'] ?? '',
        ];
    }

    private function downloadSharedFolder($file)
    {
        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];

        if (!is_dir($fullPath)) {
            return ['success' => false, 'message' => '文件夹不存在'];
        }

        $safeName = Security::sanitizeFilename($file['filename']);
        $zipFile = UPLOAD_PATH . DIRECTORY_SEPARATOR . $safeName . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.zip';

        if (file_exists($zipFile)) {
            return ['success' => false, 'message' => '临时文件创建失败，请稍后再试'];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE) !== true) {
            return ['success' => false, 'message' => '无法创建压缩文件'];
        }

        $this->addDirToZip($zip, $fullPath, $file['filename']);
        $zip->close();

        return [
            'success' => true,
            'path' => $zipFile,
            'filename' => $file['filename'] . '.zip',
            'mime' => 'application/zip',
            'size' => filesize($zipFile),
            'temp' => true,
        ];
    }

    private function addDirToZip($zip, $dir, $prefix)
    {
        $zip->addEmptyDir($prefix);
        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            $safeItem = str_replace(['..', '\\', '/'], '', $item);
            if ($safeItem !== $item || strpos($item, '..') !== false) {
                continue;
            }

            $zipPath = $prefix . DIRECTORY_SEPARATOR . $safeItem;

            if (is_dir($path)) {
                $this->addDirToZip($zip, $path, $zipPath);
            } else {
                $zip->addFile($path, $zipPath);
            }
        }
    }

    public function listShares($page = 1, $pageSize = 20)
    {
        $userId = $this->auth->getUserId();
        $offset = ($page - 1) * $pageSize;

        // 分享列表读多写少，走 fetchCached（shares/files 表变更时自动失效）
        $shares = $this->db->fetchCached(
            "SELECT s.*, f.filename, f.filesize, f.file_type, f.is_dir
             FROM shares s
             LEFT JOIN files f ON s.file_id = f.id
             WHERE s.user_id = ?
             ORDER BY s.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $pageSize, $offset],
            ['shares', 'files', 'user:' . $userId]
        );

        foreach ($shares as &$share) {
            $share['share_url'] = $this->getShareUrl($share['share_token']);
            $share['created_at_formatted'] = Security::formatTime($share['created_at']);
            $share['expire_at_formatted'] = $share['expire_at'] > 0 ? Security::formatTime($share['expire_at']) : '永久';
            // is_expired 用 time() 实时计算，缓存命中时也能反映当前过期状态
            $share['is_expired'] = ($share['expire_at'] > 0 && $share['expire_at'] < time()) || ($share['max_downloads'] > 0 && $share['download_count'] >= $share['max_downloads']);
            $share['has_password'] = !empty($share['share_password']);
        }

        return $shares;
    }

    public function getSharesCount()
    {
        $userId = $this->auth->getUserId();
        $result = $this->db->fetchOneCached(
            "SELECT COUNT(*) as count FROM shares WHERE user_id = ?",
            [$userId],
            ['shares', 'user:' . $userId]
        );
        return $result['count'];
    }

    public function deleteShare($shareId)
    {
        $userId = $this->auth->getUserId();
        $share = $this->db->fetch("SELECT * FROM shares WHERE id = ? AND user_id = ?", [$shareId, $userId]);

        if (!$share) {
            return ['success' => false, 'message' => '分享不存在'];
        }

        $this->db->delete('shares', 'id = ? AND user_id = ?', [$shareId, $userId]);

        $this->logOperation('delete_share', $share['share_token']);

        return ['success' => true, 'message' => '分享已删除'];
    }

    public function toggleShare($shareId)
    {
        $userId = $this->auth->getUserId();
        $share = $this->db->fetch("SELECT * FROM shares WHERE id = ? AND user_id = ?", [$shareId, $userId]);

        if (!$share) {
            return ['success' => false, 'message' => '分享不存在'];
        }

        $newStatus = $share['is_active'] ? 0 : 1;
        $this->db->update('shares', ['is_active' => $newStatus], 'id = ? AND user_id = ?', [$shareId, $userId]);

        return ['success' => true, 'message' => $newStatus ? '分享已启用' : '分享已禁用', 'is_active' => $newStatus];
    }

    public function recordVisit($shareId, $visitType = 'view')
    {
        $ip = Security::getClientIP();
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $referer = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500);

        $this->db->insert('share_visits', [
            'share_id' => intval($shareId),
            'ip' => $ip,
            'user_agent' => $userAgent,
            'referer' => $referer,
            'visit_type' => in_array($visitType, ['view', 'download']) ? $visitType : 'view',
            'country' => '',
            'city' => '',
            'created_at' => time(),
        ]);

        // 新访问记录会让 getShareStats 的缓存过期，主动失效
        // （db->insert 已通过 invalidateTableCache('share_visits') 处理，此处显式调用确保）
        $this->db->invalidateTableCache('share_visits');
    }

    public function getShareStats($shareId)
    {
        $userId = $this->auth->getUserId();
        $share = $this->db->fetchOneCached(
            "SELECT * FROM shares WHERE id = ? AND user_id = ?",
            [$shareId, $userId],
            ['shares']
        );
        if (!$share) {
            return ['success' => false, 'message' => '分享不存在'];
        }

        // 访问统计读多写少，走 fetchCached（share_visits 表变更时自动失效）
        $totalViews = $this->db->fetchOneCached(
            "SELECT COUNT(*) as count FROM share_visits WHERE share_id = ? AND visit_type = 'view'",
            [$shareId],
            ['share_visits', 'shares']
        );
        $totalDownloads = $this->db->fetchOneCached(
            "SELECT COUNT(*) as count FROM share_visits WHERE share_id = ? AND visit_type = 'download'",
            [$shareId],
            ['share_visits', 'shares']
        );

        $sevenDaysAgo = time() - 7 * 86400;
        $dailyStats = $this->db->fetchCached(
            "SELECT DATE(created_at, 'unixepoch') as date,
                    SUM(CASE WHEN visit_type = 'view' THEN 1 ELSE 0 END) as views,
                    SUM(CASE WHEN visit_type = 'download' THEN 1 ELSE 0 END) as downloads
             FROM share_visits
             WHERE share_id = ? AND created_at >= ?
             GROUP BY date ORDER BY date",
            [$shareId, $sevenDaysAgo],
            ['share_visits', 'shares']
        );

        $uniqueIps = $this->db->fetchOneCached(
            "SELECT COUNT(DISTINCT ip) as count FROM share_visits WHERE share_id = ? AND ip != ''",
            [$shareId],
            ['share_visits', 'shares']
        );

        $recentVisits = $this->db->fetchCached(
            "SELECT * FROM share_visits WHERE share_id = ? ORDER BY created_at DESC LIMIT 50",
            [$shareId],
            ['share_visits', 'shares']
        );

        foreach ($recentVisits as &$visit) {
            $visit['created_at_formatted'] = Security::formatTime($visit['created_at']);
        }

        return [
            'success' => true,
            'stats' => [
                'total_views' => $totalViews['count'],
                'total_downloads' => $totalDownloads['count'],
                'unique_visitors' => $uniqueIps['count'],
                'daily_stats' => $dailyStats,
                'recent_visits' => $recentVisits,
            ]
        ];
    }

    private function getShareUrl($token)
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        // 只取 HOST 的第一段（域名或 IP），丢弃端口和恶意值
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $host = explode(':', $host)[0];
        // 验证是否为合法域名或 IP，否则降级
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?(\.[a-zA-Z]{2,})?$/', $host)
            && !filter_var($host, FILTER_VALIDATE_IP)) {
            $host = 'localhost';
        }
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/';
        $scriptDir = dirname($scriptName);
        $scriptDir = $scriptDir === '\\' || $scriptDir === '/' ? '' : $scriptDir;
        return $protocol . '://' . $host . $scriptDir . '/index.php?page=share&token=' . $token;
    }

    private function addImageWatermark($imagePath, $ext, $share)
    {
        $imageData = @file_get_contents($imagePath);
        if ($imageData === false) return null;

        $image = @imagecreatefromstring($imageData);
        if ($image === false) return null;

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width < 200 || $height < 200) {
            imagedestroy($image);
            return null;
        }

        $watermarkText = date('Y-m-d');
        $fontSize = max(12, intval(min($width, $height) / 25));

        $fontPath = null;
        $fontDirs = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            'C:/Windows/Fonts/msyh.ttc',
            'C:/Windows/Fonts/arial.ttf',
        ];
        foreach ($fontDirs as $dir) {
            if (file_exists($dir)) {
                $fontPath = $dir;
                break;
            }
        }

        $textColor = imagecolorallocatealpha($image, 128, 128, 128, 80);

        if ($fontPath && function_exists('imagettftext')) {
            $angle = -30;
            $padding = $fontSize * 2;
            $x = $width - $padding - $fontSize * strlen($watermarkText) * 0.5;
            $y = $height - $padding;
            imagettftext($image, $fontSize, $angle, $x, $y, $textColor, $fontPath, $watermarkText);
        } else {
            $padding = 10;
            $x = $width - imagefontwidth(5) * strlen($watermarkText) - $padding;
            $y = $height - imagefontheight(5) - $padding;
            imagestring($image, 5, $x, $y, $watermarkText, $textColor);
        }

        $tempDir = UPLOAD_PATH;
        if (!is_dir($tempDir)) {
            imagedestroy($image);
            return null;
        }
        $tempPath = $tempDir . DIRECTORY_SEPARATOR . 'wm_' . $share['id'] . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);

        $saveResult = false;
        switch ($ext) {
            case 'png':
                imagesavealpha($image, true);
                $saveResult = imagepng($image, $tempPath, 8);
                break;
            case 'gif':
                $saveResult = imagegif($image, $tempPath);
                break;
            case 'webp':
                $saveResult = imagewebp($image, $tempPath, 80);
                break;
            default:
                $saveResult = imagejpeg($image, $tempPath, 85);
                break;
        }

        imagedestroy($image);

        if (!$saveResult) {
            if (file_exists($tempPath)) @unlink($tempPath);
            return null;
        }

        return $tempPath;
    }

}
