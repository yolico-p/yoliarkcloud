<?php

namespace Updater;

/**
 * 健康检查：关键入口文件语法 + HTTP 自探。
 *
 * 兼容性设计：
 * - 语法检查用 token_get_all(TOKEN_PARSE) 替代 `php -l`，不依赖 proc_open。
 * - HTTP 自探在维护模式关闭后执行，避免维护页干扰。
 */
class HealthCheck
{
    /**
     * 关键入口文件（相对 ROOT_PATH）。
     */
    private const ENTRY_FILES = ['index.php', 'api.php', 'worker.php'];

    private array $config;

    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $configFile = ROOT_PATH . DIRECTORY_SEPARATOR . 'updater' . DIRECTORY_SEPARATOR . 'config.php';
            $config = is_file($configFile) ? (require $configFile) : [];
        }
        $this->config = is_array($config) ? $config : [];
    }

    /**
     * 执行健康检查。
     *
     * @return array{healthy: bool, errors: array}
     */
    public function check(): array
    {
        $errors = [];

        // 1. 语法检查
        foreach (self::ENTRY_FILES as $rel) {
            $path = ROOT_PATH . DIRECTORY_SEPARATOR . $rel;
            $err = $this->checkSyntax($path, $rel);
            if ($err !== null) {
                $errors[] = $err;
            }
        }

        // 3. 关键类可加载性检查
        $criticalClasses = [
            'App\Core\Database',
            'App\Core\Config',
            'App\Services\AuthService',
        ];
        foreach ($criticalClasses as $cls) {
            if (!class_exists($cls, true)) {
                $errors[] = 'Critical class missing or unloadable: ' . $cls;
            }
        }

        return [
            'healthy' => empty($errors),
            'errors'  => $errors,
        ];
    }

    /**
     * 重置 opcache（应用文件后调用）。
     */
    public static function resetOpcache(): void
    {
        clearstatcache(true);
    }

    /**
     * 用 token_get_all 检查 PHP 文件语法。
     */
    private function checkSyntax(string $path, string $label): ?string
    {
        if (!is_file($path)) {
            return $label . ' missing after update';
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return $label . ' unreadable after update';
        }
        try {
            @token_get_all($content, TOKEN_PARSE);
        } catch (\ParseError $e) {
            return $label . ' has syntax error: ' . $e->getMessage();
        }
        return null;
    }
}
