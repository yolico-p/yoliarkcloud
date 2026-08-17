<?php

namespace Updater;

/**
 * 校验器。
 *
 * 安全设计（对照 update-server-guide.md 第 7、10、14 节）：
 * - 签名强制：signatureUrl 缺失或拉取失败 → 拒绝；公钥未配置 → 拒绝；绝不降级为“仅 SHA256”。
 * - SHA256 强制：与 versions.json 声明比对，hash_equals 防时序攻击。
 * - Zip Slip 防护：解压前逐条目校验路径不含 `..` / 绝对路径。
 * - 禁止路径检查：解压后检测包内是否含 storage/uploads/updater/config.json 等用户数据与子系统自身。
 * - MANIFEST.json 容错：包内无 MANIFEST.json 时跳过逐文件校验（SHA256 + 签名已保证完整性）。
 */
class Verifier
{
    private string $extractedDir;

    /** 包内禁止出现的顶层条目（用户数据、开发产物、敏感配置）。
     *  updater 不禁止：服务端打包可能包含，客户端应用时通过 APPLY_SKIP_DIRS 跳过。 */
    private const FORBIDDEN_TOP_ENTRIES = [
        'storage',
        'uploads',
        'config.json',
        '.git',
        '.trae',
        '.maintenance',
        '.instance_id',
        '.update.lock',
        '.update_failed',
        '.worker_stop',
        '.worker_heartbeat',
    ];

    public function __construct()
    {
        $this->extractedDir = UPDATE_STAGING_PATH . DIRECTORY_SEPARATOR . 'extracted';
    }

    /**
     * 校验更新包。
     *
     * @param array{sha256: string, signatureUrl?: string, minPhpVersion?: string} $expectedInfo
     * @return array{valid: bool, errors: array, extractedDir: string, manifestFiles?: array}
     */
    public function verify(string $packagePath, array $expectedInfo): array
    {
        $errors = [];

        if (!is_file($packagePath)) {
            return [
                'valid'        => false,
                'errors'       => ['Package file not found: ' . $packagePath],
                'extractedDir' => $this->extractedDir,
            ];
        }

        // 1. 签名校验（强制）
        $signatureUrl = (string)($expectedInfo['signatureUrl'] ?? '');
        if ($signatureUrl === '') {
            return [
                'valid'        => false,
                'errors'       => ['Missing signatureUrl for package'],
                'extractedDir' => $this->extractedDir,
            ];
        }
        $sigError = $this->verifyPackageSignature($packagePath, $signatureUrl);
        if ($sigError !== null) {
            return [
                'valid'        => false,
                'errors'       => [$sigError],
                'extractedDir' => $this->extractedDir,
            ];
        }

        // 2. SHA256
        $expectedSha = strtolower((string)($expectedInfo['sha256'] ?? ''));
        if ($expectedSha === '') {
            return [
                'valid'        => false,
                'errors'       => ['Missing expected SHA256'],
                'extractedDir' => $this->extractedDir,
            ];
        }
        $actual = hash_file('sha256', $packagePath);
        if ($actual === false) {
            return [
                'valid'        => false,
                'errors'       => ['Failed to compute package SHA256'],
                'extractedDir' => $this->extractedDir,
            ];
        }
        if (!hash_equals($expectedSha, strtolower($actual))) {
            return [
                'valid'        => false,
                'errors'       => ['Package SHA256 mismatch: expected ' . $expectedSha . ', got ' . $actual],
                'extractedDir' => $this->extractedDir,
            ];
        }

        // 3. Zip Slip 防护 + 解压
        $extractErrors = $this->extract($packagePath);
        if (!empty($extractErrors)) {
            return [
                'valid'        => false,
                'errors'       => $extractErrors,
                'extractedDir' => $this->extractedDir,
            ];
        }

        // 4. 禁止路径检查
        $forbiddenErrors = $this->checkForbiddenEntries();
        if (!empty($forbiddenErrors)) {
            return [
                'valid'        => false,
                'errors'       => $forbiddenErrors,
                'extractedDir' => $this->extractedDir,
            ];
        }

        // 5. 包内 MANIFEST.json 逐文件校验（不存在则跳过）
        $manifestResult = $this->verifyExtractedManifest();
        if (!empty($manifestResult['errors'])) {
            return [
                'valid'        => false,
                'errors'       => $manifestResult['errors'],
                'extractedDir' => $this->extractedDir,
            ];
        }

        return [
            'valid'        => true,
            'errors'       => [],
            'extractedDir' => $this->extractedDir,
            'manifestFiles' => $manifestResult['files'],
        ];
    }

    private function verifyPackageSignature(string $packagePath, string $signatureUrl): ?string
    {
        if (strpos($signatureUrl, 'https://') !== 0) {
            return 'Signature URL must use HTTPS: ' . $signatureUrl;
        }

        $ch = curl_init($signatureUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => self::userAgent(),
        ]);
        $signature = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);

        if ($signature === false || $code !== 200 || $signature === '') {
            return 'Failed to fetch package signature (HTTP ' . $code . ')';
        }

        $signature = (string)$signature;
        $sigBin = $this->decodeSignature($signature);
        if ($sigBin === null) {
            return 'Package signature has invalid length';
        }

        try {
            $pub = PublicKey::getPublicKey();
        } catch (\Throwable $e) {
            return 'Public key unavailable: ' . $e->getMessage();
        }

        $packageData = @file_get_contents($packagePath);
        if ($packageData === false) {
            return 'Failed to read package for signature verification';
        }

        if (!sodium_crypto_sign_verify_detached($sigBin, $packageData, $pub)) {
            return 'Package signature verification failed';
        }

        return null;
    }

    /**
     * 解压前校验所有条目路径，再解压。
     *
     * @return array 错误列表（空数组表示成功）
     */
    private function extract(string $packagePath): array
    {
        $this->cleanup($this->extractedDir);
        if (!is_dir($this->extractedDir)) {
            @mkdir($this->extractedDir, 0755, true);
        }

        $zip = new \ZipArchive();
        $status = $zip->open($packagePath);
        if ($status !== true) {
            return ['Failed to open package as zip (code=' . $status . ')'];
        }

        // Zip Slip 防护：逐条目校验
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $name = (string)$stat['name'];
            if ($this->isUnsafeEntryName($name)) {
                $zip->close();
                return ['Unsafe zip entry path (possible zip slip): ' . $name];
            }
        }

        if (!$zip->extractTo($this->extractedDir)) {
            $zip->close();
            return ['Failed to extract package'];
        }
        $zip->close();

        return [];
    }

    /**
     * 判断 zip 条目名是否不安全：含 `..` 路径段或绝对路径。
     */
    private function isUnsafeEntryName(string $name): bool
    {
        if ($name === '') {
            return false;
        }
        // 统一为正斜杠
        $normalized = str_replace('\\', '/', $name);
        // 绝对路径（Unix 或 Windows 盘符）
        if ($normalized[0] === '/') {
            return true;
        }
        if (preg_match('#^[A-Za-z]:#', $normalized)) {
            return true;
        }
        // 拆段检查 `..`
        $parts = explode('/', $normalized);
        foreach ($parts as $part) {
            if ($part === '..') {
                return true;
            }
        }
        return false;
    }

    /**
     * 检测解压目录顶层是否含禁止条目。
     */
    private function checkForbiddenEntries(): array
    {
        $errors = [];
        $tops = @scandir($this->extractedDir);
        if ($tops === false) {
            return ['Failed to scan extracted directory'];
        }
        foreach ($tops as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (in_array($entry, self::FORBIDDEN_TOP_ENTRIES, true)) {
                $errors[] = 'Package contains forbidden entry: ' . $entry;
            }
        }
        return $errors;
    }

    /**
     * 校验解压目录中的 MANIFEST.json（如果存在）。
     *
     * 兼容两种 files 格式：
     * - 规范数组：[{"path":"...","sha256":"...","size":N,"mode":"0644"}, ...]
     * - 旧版关联：{"path": "sha256", ...}
     *
     * @return array{errors: array, files: array}
     */
    private function verifyExtractedManifest(): array
    {
        $manifestFile = $this->extractedDir . DIRECTORY_SEPARATOR . 'MANIFEST.json';
        if (!is_file($manifestFile)) {
            // 包内无 MANIFEST.json → 跳过逐文件校验
            return ['errors' => [], 'files' => []];
        }

        $data = json_decode((string)file_get_contents($manifestFile), true);
        if (!is_array($data) || !isset($data['files'])) {
            return ['errors' => ['Invalid or missing "files" section in MANIFEST.json'], 'files' => []];
        }

        $files = $data['files'];
        $fileList = [];

        // 数组格式
        if (is_array($files) && isset($files[0]) && is_array($files[0])) {
            foreach ($files as $entry) {
                $rel = (string)($entry['path'] ?? '');
                $expectedHash = strtolower((string)($entry['sha256'] ?? ''));
                if ($rel === '' || $expectedHash === '') {
                    continue;
                }
                $fileList[] = ['path' => $rel, 'sha256' => $expectedHash, 'mode' => $entry['mode'] ?? null];
            }
        } else {
            // 关联格式 path => sha256
            foreach ($files as $rel => $expectedHash) {
                $fileList[] = ['path' => (string)$rel, 'sha256' => strtolower((string)$expectedHash), 'mode' => null];
            }
        }

        $errors = [];
        foreach ($fileList as $f) {
            $rel = $f['path'];
            $expectedHash = $f['sha256'];
            // 解析后路径不得越出 extractedDir
            $fullPath = $this->extractedDir . DIRECTORY_SEPARATOR
                . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel), DIRECTORY_SEPARATOR);
            $realBase = realpath($this->extractedDir);
            $realFile = realpath($fullPath);
            if ($realBase === false || $realFile === false || strpos($realFile, $realBase) !== 0) {
                $errors[] = 'MANIFEST.json references out-of-bound file: ' . $rel;
                continue;
            }
            if (!is_file($realFile)) {
                $errors[] = 'MANIFEST.json references missing file: ' . $rel;
                continue;
            }
            $actual = hash_file('sha256', $realFile);
            if ($actual === false || !hash_equals($expectedHash, strtolower($actual))) {
                $errors[] = 'SHA256 mismatch for ' . $rel;
            }
        }

        return ['errors' => $errors, 'files' => $fileList];
    }

    /**
     * 删除解压目录。
     */
    public function cleanup(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }
        @rmdir($dir);
    }

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
            // 忽略
        }
        return 'YoliArkCloud-Updater/' . $ver . ' (' . $os . '; PHP ' . $php . '; Instance ' . $instanceHash . ')';
    }
}
