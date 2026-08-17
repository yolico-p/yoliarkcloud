<?php

namespace Updater;

/**
 * 版本检查器。
 *
 * 安全设计（对照 update-server-guide.md 第 9、14 节）：
 * - 强制 HTTPS：sourceUrl 与 release 的 downloadUrl/signatureUrl 均校验。
 * - 强制 Ed25519 验签：versions.json.sig 拉取失败或验签失败 → 拒绝清单，绝不降级为“仅 SHA256”。
 * - 防重放：拒绝 generatedAt 早于上次成功检查的清单；拒绝过期清单（expiresAt）。
 * - 版本约束：manifestVersion / minPhpVersion / maxPhpVersion / minCurrentVersion / revokedVersions。
 */
class Checker
{
    private string $sourceUrl;

    public function __construct(?string $sourceUrl = null)
    {
        if ($sourceUrl === null) {
            $config = require ROOT_PATH . DIRECTORY_SEPARATOR . 'updater' . DIRECTORY_SEPARATOR . 'config.php';
            $sourceUrl = $config['update_source_url'] ?? 'https://yoliarkupdate.yoliark.com/';
        }
        $this->sourceUrl = rtrim($sourceUrl, '/') . '/';
        $this->ensureHttps($this->sourceUrl);
    }

    /**
     * 检查更新。
     *
     * @return array{
     *   hasUpdate: bool,
     *   latestVersion?: string,
     *   downloadUrl?: string,
     *   signatureUrl?: string,
     *   sha256?: string,
     *   packageSize?: int,
     *   features?: array,
     *   releaseNotesMd?: string,
     *   mandatory?: bool,
     *   criticalSecurityUpdate?: bool,
     *   minPhpVersion?: string,
     *   manifestGeneratedAt?: int,
     *   message?: string,
     *   error?: string
     * }
     */
    public function check(string $currentVersion, string $phpVersion = PHP_VERSION): array
    {
        // 1. 拉取 versions.json
        $versionsResp = $this->httpGet($this->sourceUrl . 'versions.json');
        if ($versionsResp['code'] !== 200 || $versionsResp['body'] === '') {
            return ['hasUpdate' => false, 'error' => 'Failed to fetch versions.json (HTTP ' . $versionsResp['code'] . ')'];
        }
        $manifestData = $versionsResp['body'];

        // 2. 拉取签名（强制：失败即拒绝，不降级）
        $sigResp = $this->httpGet($this->sourceUrl . 'versions.json.sig');
        if ($sigResp['code'] !== 200 || $sigResp['body'] === '') {
            return ['hasUpdate' => false, 'error' => 'Failed to fetch versions.json.sig (HTTP ' . $sigResp['code'] . ')'];
        }
        $signature = $sigResp['body'];

        // 3. Ed25519 验签（强制）
        try {
            $pub = PublicKey::getPublicKey();
        } catch (\Throwable $e) {
            return ['hasUpdate' => false, 'error' => 'Public key unavailable: ' . $e->getMessage()];
        }
        $sigBin = $this->decodeSignature($signature);
        if ($sigBin === null || !sodium_crypto_sign_verify_detached($sigBin, $manifestData, $pub)) {
            return ['hasUpdate' => false, 'error' => 'Signature verification failed for versions.json'];
        }

        // 4. 解析
        $manifest = json_decode($manifestData, true);
        if (!is_array($manifest)) {
            return ['hasUpdate' => false, 'error' => 'versions.json is not valid JSON'];
        }
        if (($manifest['manifestVersion'] ?? 0) !== 2) {
            return ['hasUpdate' => false, 'error' => 'Unsupported manifestVersion'];
        }

        // 5. 防重放：generatedAt / expiresAt
        $generatedAt = (int)($manifest['generatedAt'] ?? 0);
        $expiresAt   = (int)($manifest['expiresAt'] ?? 0);
        $now         = time();
        if ($generatedAt <= 0) {
            return ['hasUpdate' => false, 'error' => 'Missing generatedAt in manifest'];
        }
        $lastGenerated = Manifest::getLastManifestGeneratedAt();
        if ($lastGenerated > 0 && $generatedAt < $lastGenerated) {
            return ['hasUpdate' => false, 'error' => 'Manifest generatedAt older than last successful check (possible replay)'];
        }
        if ($expiresAt > 0 && $expiresAt < $now) {
            return ['hasUpdate' => false, 'error' => 'Manifest has expired'];
        }

        $latestVersion       = (string)($manifest['latestVersion'] ?? '');
        $latestStableVersion = (string)($manifest['latestStableVersion'] ?? $latestVersion);
        $minimumRequired     = (string)($manifest['minimumRequiredVersion'] ?? '');

        if ($latestVersion === '') {
            return ['hasUpdate' => false, 'error' => 'Missing latestVersion in manifest'];
        }

        // 6. 版本比对
        if (!self::isNewer($latestVersion, $currentVersion)) {
            Manifest::setLastManifestGeneratedAt($generatedAt);
            return [
                'hasUpdate'     => false,
                'latestVersion' => $latestVersion,
                'message'       => 'Already up to date.',
            ];
        }

        // 7. release 详情
        $release = $manifest['releases'][$latestVersion] ?? null;
        if (!is_array($release)) {
            return ['hasUpdate' => false, 'error' => 'Release entry not found for ' . $latestVersion];
        }

        // 8. release URL HTTPS 强制
        $downloadUrl  = (string)($release['downloadUrl'] ?? '');
        $signatureUrl = (string)($release['signatureUrl'] ?? '');
        if ($downloadUrl === '' || $signatureUrl === '') {
            return ['hasUpdate' => false, 'error' => 'Release missing downloadUrl or signatureUrl'];
        }
        try {
            $this->ensureHttps($downloadUrl);
        } catch (\Throwable $e) {
            return ['hasUpdate' => false, 'error' => $e->getMessage()];
        }
        try {
            $this->ensureHttps($signatureUrl);
        } catch (\Throwable $e) {
            return ['hasUpdate' => false, 'error' => $e->getMessage()];
        }

        // 9. SHA256 格式校验（64 位小写十六进制）
        $sha256 = strtolower((string)($release['sha256'] ?? ''));
        if ($sha256 === '' || strlen($sha256) !== 64 || !ctype_xdigit($sha256)) {
            return ['hasUpdate' => false, 'error' => 'Release sha256 is invalid'];
        }

        // 10. PHP 版本约束
        $minPhp = (string)($release['minPhpVersion'] ?? '8.0.0');
        $maxPhp = (string)($release['maxPhpVersion'] ?? '');
        if (version_compare($phpVersion, $minPhp, '<')) {
            return [
                'hasUpdate'     => false,
                'latestVersion' => $latestVersion,
                'error'         => 'PHP version not satisfied: requires >=' . $minPhp . ', got ' . $phpVersion,
            ];
        }
        if ($maxPhp !== '' && version_compare($phpVersion, $maxPhp, '>')) {
            return [
                'hasUpdate'     => false,
                'latestVersion' => $latestVersion,
                'error'         => 'PHP version too high: requires <=' . $maxPhp . ', got ' . $phpVersion,
            ];
        }

        // 11. minCurrentVersion 跨版本升级限制
        $minCurrent = (string)($release['minCurrentVersion'] ?? '');
        if ($minCurrent !== '' && version_compare($currentVersion, $minCurrent, '<')) {
            return [
                'hasUpdate'     => false,
                'latestVersion' => $latestVersion,
                'error'         => 'Current version too old: requires >=' . $minCurrent . ' to upgrade to ' . $latestVersion,
            ];
        }

        // 12. revokedVersions / 强制更新
        $revokedVersions        = $manifest['revokedVersions'] ?? [];
        $mandatory              = in_array($currentVersion, $revokedVersions, true)
            || (bool)($release['mandatory'] ?? false);
        $criticalSecurityUpdate = (bool)($release['criticalSecurityUpdate'] ?? false);

        // 13. 记录 manifest 生成时间作为防重放基准（验签通过后才记录）
        Manifest::setLastManifestGeneratedAt($generatedAt);

        return [
            'hasUpdate'              => true,
            'latestVersion'          => $latestVersion,
            'downloadUrl'            => $downloadUrl,
            'signatureUrl'           => $signatureUrl,
            'sha256'                 => $sha256,
            'packageSize'            => (int)($release['packageSize'] ?? 0),
            'features'               => (array)($release['features'] ?? []),
            'releaseNotesMd'         => (string)($release['releaseNotesMd'] ?? ''),
            'mandatory'              => $mandatory,
            'criticalSecurityUpdate' => $criticalSecurityUpdate,
            'minPhpVersion'          => $minPhp,
            'minimumRequiredVersion' => $minimumRequired,
            'latestStableVersion'    => $latestStableVersion,
            'manifestGeneratedAt'    => $generatedAt,
        ];
    }

    /**
     * 强制 HTTPS，HTTP 直接抛错。
     */
    private function ensureHttps(string $url): void
    {
        $parsed = parse_url($url);
        if (!isset($parsed['scheme']) || strtolower($parsed['scheme']) !== 'https') {
            throw new \RuntimeException('Update source must use HTTPS: ' . $url);
        }
    }

    /**
     * @return array{code: int, body: string}
     */
    private function httpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => self::userAgent(),
        ]);

        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);

        if ($body === false) {
            return ['code' => 0, 'body' => ''];
        }
        return ['code' => $code, 'body' => (string)$body];
    }

    /**
     * 解码签名：raw 二进制优先，其次 hex。
     */
    private function decodeSignature(string $signature): ?string
    {
        if (strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES) {
            return $signature;
        }
        $hex = @hex2bin($signature);
        if ($hex !== false && strlen($hex) === SODIUM_CRYPTO_SIGN_BYTES) {
            return $hex;
        }
        return null;
    }

    /**
     * 标准化 User-Agent（update-server-guide.md 附录 C）。
     */
    private static function userAgent(): string
    {
        $ver = defined('PANCLOUD_VERSION') ? PANCLOUD_VERSION : '0.0.0';
        $os  = PHP_OS_FAMILY;
        $php = PHP_VERSION;
        $instanceHash = '';
        try {
            $instanceHash = substr(Manifest::getInstanceId(), 0, 8);
        } catch (\Throwable $e) {
            // 忽略：实例 ID 不可用时 UA 仍可用
        }
        return 'YoliArkCloud-Updater/' . $ver . ' (' . $os . '; PHP ' . $php . '; Instance ' . $instanceHash . ')';
    }

    /**
     * 版本号比较：v 是否严格大于 current。
     */
    public static function isNewer(string $v, string $current): bool
    {
        return version_compare($v, $current, '>');
    }
}
