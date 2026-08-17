<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Core\Config;

class AIConfigService
{
    private $db;
    private $config;
    private $apiKey;
    private $baseUrl;
    private $model;
    private $maxTokens = 12288;
    private $tokenReserve = 1000; // 预留 1000 tokens 给新回复

    // 常见模型的 max_tokens 上限（回复 token 约束），低于此值则使用用户配置
    private static array $modelMaxTokens = [
        'gpt-4o' => 16384,
        'gpt-4o-mini' => 16384,
        'gpt-4' => 8192,
        'gpt-4-turbo' => 4096,
        'gpt-3.5-turbo' => 4096,
        'deepseek-chat' => 8192,
        'deepseek-reasoner' => 8192,
        'qwen-plus' => 8192,
        'qwen-max' => 8192,
        'qwen-turbo' => 8192,
        'glm-4' => 4096,
        'glm-4-plus' => 8192,
        'moonshot-v1' => 4096,
        'claude-3-opus' => 4096,
        'claude-3-sonnet' => 4096,
        'claude-3-haiku' => 4096,
        'claude-3-5-sonnet' => 8192,
    ];

    // 模型思考/推理能力注册表：记录哪些模型支持 thinking/reasoning 参数
    private static array $modelCapabilities = [
        // DeepSeek models - support 'thinking' parameter
        'deepseek-v4-flash' => ['param' => 'thinking', 'levels' => ['low', 'medium', 'high'], 'default' => 'high'],
        'deepseek-reasoner' => ['param' => 'thinking', 'levels' => ['low', 'medium', 'high'], 'default' => 'high'],
        'deepseek-chat' => ['param' => 'thinking', 'levels' => ['low', 'medium', 'high'], 'default' => 'medium'],
        // Qwen models - support 'thinking' parameter
        'qwen3-thinking' => ['param' => 'thinking', 'levels' => ['low', 'medium', 'high'], 'default' => 'high'],
        'qwen-max' => ['param' => 'enable_thinking', 'levels' => [false, true], 'default' => true],
        // OpenAI o-series - support 'reasoning_effort' parameter
        'o1' => ['param' => 'reasoning_effort', 'levels' => ['low', 'medium', 'high'], 'default' => 'high'],
        'o3' => ['param' => 'reasoning_effort', 'levels' => ['low', 'medium', 'high'], 'default' => 'high'],
        'o3-mini' => ['param' => 'reasoning_effort', 'levels' => ['low', 'medium', 'high'], 'default' => 'medium'],
        // Claude thinking models
        'claude-3-7-sonnet' => ['param' => 'thinking', 'levels' => ['enabled' => [false, true]], 'default' => ['enabled' => true]],
    ];

    // 常见模型的上下文窗口上限（tokens）
    private static array $modelContextLimits = [
        // GLM series
        'glm-4-flash' => 128000,
        'glm-4' => 128000,
        'glm-4-plus' => 128000,
        'glm-4-long' => 1000000,
        // DeepSeek
        'deepseek-chat' => 64000,
        'deepseek-reasoner' => 64000,
        'deepseek-v4-flash' => 64000,
        // Qwen
        'qwen-max' => 32000,
        'qwen-plus' => 128000,
        'qwen-turbo' => 128000,
        // Moonshot Kimi
        'moonshot-v1-8k' => 8000,
        'moonshot-v1-32k' => 32000,
        'moonshot-v1-128k' => 128000,
        'moonshot-v1-1m' => 1000000,
        // Claude
        'claude-3-opus' => 200000,
        'claude-3-sonnet' => 200000,
        'claude-3-haiku' => 200000,
        'claude-3-5-sonnet' => 200000,
        'claude-3-7-sonnet' => 200000,
        // GPT
        'gpt-4o' => 128000,
        'gpt-4o-mini' => 128000,
        'gpt-4' => 8192,
        'gpt-4-turbo' => 128000,
        'gpt-3.5-turbo' => 16385,
        'o1' => 200000,
        'o3' => 200000,
        'o3-mini' => 200000,
    ];

    private static $presetProviders = [
        'zhipu' => [
            'name' => '智谱AI',
            'base_url' => 'https://open.bigmodel.cn/api/paas/v4',
            'desc' => 'GLM 系列模型，GLM-4-Flash 免费使用',
            'docs' => 'https://open.bigmodel.cn',
        ],
        'deepseek' => [
            'name' => 'DeepSeek',
            'base_url' => 'https://api.deepseek.com',
            'desc' => 'DeepSeek-V4 系列，代码能力极强',
            'docs' => 'https://platform.deepseek.com',
        ],
        'siliconflow' => [
            'name' => '硅基流动',
            'base_url' => 'https://api.siliconflow.cn/v1',
            'desc' => '聚合多款开源模型，部分免费',
            'docs' => 'https://cloud.siliconflow.cn',
        ],
        'moonshot' => [
            'name' => 'Moonshot (Kimi)',
            'base_url' => 'https://api.moonshot.cn/v1',
            'desc' => 'Kimi 系列模型，长上下文',
            'docs' => 'https://platform.moonshot.cn',
        ],
        'qwen' => [
            'name' => '通义千问',
            'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
            'desc' => '阿里云百炼，通义千问系列',
            'docs' => 'https://dashscope.console.aliyun.com',
        ],
        'aiping' => [
            'name' => 'AI Ping',
            'base_url' => 'https://aiping.cn/api/v1',
            'desc' => '聚合平台，GLM/MiniMax 等免费模型',
            'docs' => 'https://www.aiping.cn',
        ],
        'yi' => [
            'name' => '零一万物 (Yi)',
            'base_url' => 'https://api.lingyiwanwu.com/v1',
            'desc' => 'Yi 系列模型',
            'docs' => 'https://platform.lingyiwanwu.com',
        ],
        'ollama' => [
            'name' => 'Ollama (本地)',
            'base_url' => 'http://localhost:11434/v1',
            'desc' => '本地部署模型，无需 API Key',
            'docs' => 'https://ollama.com',
        ],
        'custom' => [
            'name' => '自定义 (OpenAI 兼容)',
            'base_url' => '',
            'desc' => '任何兼容 OpenAI API 的服务',
            'docs' => '',
        ],
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = Config::getInstance();
        $this->loadAIConfig();
    }

    public function getApiKey()
    {
        return $this->apiKey;
    }

    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    public function getModel()
    {
        return $this->model;
    }

    public function getMaxTokens()
    {
        return $this->maxTokens;
    }

    // 从模型名推断推荐 max_tokens，fallback 到默认值 4096
    public function resolveMaxTokens(?string $modelName = null): int
    {
        $model = $modelName ?? $this->model;
        if ($model === null) {
            return $this->maxTokens;
        }
        $lower = strtolower($model);
        foreach (self::$modelMaxTokens as $key => $max) {
            if (str_contains($lower, strtolower($key))) {
                return min($max, 16384);
            }
        }
        return $this->maxTokens;
    }

    /**
     * Get thinking/reasoning config for a model.
     * Returns null if model doesn't support thinking parameters.
     * Uses prefix matching (e.g. 'deepseek-v4-flash-001' matches 'deepseek-v4-flash').
     */
    public function getThinkingConfig(?string $model = null): ?array
    {
        $model = $model ?? $this->model;
        if (empty($model)) return null;

        $lower = strtolower($model);
        foreach (self::$modelCapabilities as $key => $config) {
            if (str_contains($lower, strtolower($key))) {
                return $config;
            }
        }
        return null;
    }

    /**
     * Resolve max context window for a model.
     * Hard cap at 200000 tokens to control costs.
     * First checks registry, returns 200000 cap if model supports more.
     * Default 32000 if unknown.
     */
    public function resolveMaxContext(?string $model = null): int
    {
        $model = $model ?? $this->model;
        if (empty($model)) return 32000;

        $lower = strtolower($model);
        $contextLimit = 32000; // default

        foreach (self::$modelContextLimits as $key => $limit) {
            if (str_contains($lower, strtolower($key))) {
                $contextLimit = $limit;
                break;
            }
        }

        // 200k hard cap
        return min($contextLimit, 200000);
    }

    /**
     * Get max context from saved config, fallback to resolving from current model.
     * Always enforces 200k hard cap.
     */
    public function getMaxContext(): int
    {
        $configFile = DATA_PATH . DIRECTORY_SEPARATOR . 'ai_agent.json';
        if (file_exists($configFile)) {
            $data = json_decode(file_get_contents($configFile), true);
            if (is_array($data) && !empty($data['max_context'])) {
                return min(intval($data['max_context']), 200000);
            }
        }
        // Fallback: detect from current model
        return $this->resolveMaxContext($this->model);
    }

    private function isLocalUrl($url)
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return false;

        // 明确的本地地址
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0', '[::1]'])) return true;

        // 如果是 IP 地址，检查是否为私有/保留范围
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        // 域名 → 非本地
        return false;
    }

    private function enforceHttpsIfNeeded($ch, $url)
    {
        if (!$this->isLocalUrl($url)) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
    }

    private function loadAIConfig()
    {
        $configFile = DATA_PATH . DIRECTORY_SEPARATOR . 'ai_agent.json';
        if (file_exists($configFile)) {
            $data = json_decode(file_get_contents($configFile), true);
            if (is_array($data)) {
                $this->apiKey = $data['api_key'] ?? '';
                $this->baseUrl = $data['base_url'] ?? 'https://open.bigmodel.cn/api/paas/v4';
                $this->model = $data['model'] ?? 'glm-4-flash';
                return;
            }
        }
        $this->apiKey = '';
        $this->baseUrl = 'https://open.bigmodel.cn/api/paas/v4';
        $this->model = 'glm-4-flash';
    }

    private function saveAIConfig($data)
    {
        $configFile = DATA_PATH . DIRECTORY_SEPARATOR . 'ai_agent.json';
        file_put_contents($configFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function getAIConfig()
    {
        $configFile = DATA_PATH . DIRECTORY_SEPARATOR . 'ai_agent.json';
        $data = [];
        if (file_exists($configFile)) {
            $data = json_decode(file_get_contents($configFile), true) ?: [];
        }

        $maskedKey = '';
        if (!empty($data['api_key']) && strlen($data['api_key']) > 8) {
            $maskedKey = substr($data['api_key'], 0, 4) . str_repeat('*', strlen($data['api_key']) - 8) . substr($data['api_key'], -4);
        } elseif (!empty($data['api_key'])) {
            $maskedKey = str_repeat('*', strlen($data['api_key']));
        }

        $currentProvider = $data['provider'] ?? 'zhipu';

        $providers = [];
        foreach (self::$presetProviders as $id => $p) {
            $providers[] = [
                'id' => $id,
                'name' => $p['name'],
                'base_url' => $p['base_url'],
                'desc' => $p['desc'],
                'docs' => $p['docs'],
            ];
        }

        return [
            'api_key' => $maskedKey,
            'api_key_set' => !empty($data['api_key']),
            'base_url' => $data['base_url'] ?? 'https://open.bigmodel.cn/api/paas/v4',
            'model' => $data['model'] ?? 'glm-4-flash',
            'provider' => $currentProvider,
            'enabled' => !empty($data['api_key']),
            'providers' => $providers,
        ];
    }

    public function fetchModels($apiKey, $baseUrl)
    {
        if (empty($apiKey) && !$this->isLocalUrl($baseUrl)) {
            return ['success' => false, 'message' => '请先填写 API Key'];
        }

        if (!function_exists('curl_init')) {
            return ['success' => false, 'message' => '服务器未安装 curl 扩展，无法请求外部 API'];
        }

        $url = rtrim($baseUrl, '/') . '/models';

        $headers = ['Content-Type: application/json'];
        if (!empty($apiKey)) {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => !$this->isLocalUrl($url),
            CURLOPT_SSL_VERIFYHOST => $this->isLocalUrl($url) ? 0 : 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        $this->enforceHttpsIfNeeded($ch, $url);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $curlInfo = curl_getinfo($ch);

        if ($response === false || $error) {
            $errno = $curlInfo['curl_errno'] ?? 0;
            return [
                'success' => false,
                'message' => "网络请求失败 (错误码:{$errno}): {$error}",
                'debug' => ['url' => $url, 'curl_error' => $error, 'curl_errno' => $errno],
            ];
        }

        if ($httpCode === 0) {
            return [
                'success' => false,
                'message' => '无法连接到服务器，请检查网络或 API 地址是否正确',
                'debug' => ['url' => $url],
            ];
        }

        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMsg = '未知错误';
            if (is_array($errorData)) {
                $errorMsg = $errorData['error']['message'] ?? ($errorData['message'] ?? $errorMsg);
            }
            if (empty($errorMsg) || $errorMsg === '未知错误') {
                $errorMsg = substr($response, 0, 200);
            }
            return [
                'success' => false,
                'message' => "API 返回错误 (HTTP {$httpCode}): {$errorMsg}",
                'debug' => ['url' => $url, 'http_code' => $httpCode, 'response_preview' => substr($response, 0, 500)],
            ];
        }

        $data = json_decode($response, true);
        if (!$data) {
            return [
                'success' => false,
                'message' => 'API 响应 JSON 解析失败，可能不是 OpenAI 兼容接口',
                'debug' => ['url' => $url, 'response_preview' => substr($response, 0, 500)],
            ];
        }

        if (!isset($data['data']) || !is_array($data['data'])) {
            $models = [];
            if (isset($data['models']) && is_array($data['models'])) {
                foreach ($data['models'] as $m) {
                    $id = is_string($m) ? $m : ($m['id'] ?? $m['name'] ?? '');
                    if (empty($id)) continue;
                    $models[] = ['id' => $id, 'name' => $id, 'owned_by' => ''];
                }
            } elseif (isset($data['id'])) {
                $models[] = ['id' => $data['id'], 'name' => $data['id'], 'owned_by' => ''];
            }

            if (empty($models)) {
                return [
                    'success' => false,
                    'message' => 'API 响应格式不兼容，未找到模型列表',
                    'debug' => ['url' => $url, 'response_keys' => array_keys($data), 'response_preview' => substr($response, 0, 500)],
                ];
            }

            return ['success' => true, 'models' => $models];
        }

        $models = [];
        foreach ($data['data'] as $m) {
            $id = $m['id'] ?? '';
            if (empty($id)) continue;
            $models[] = [
                'id' => $id,
                'name' => $m['name'] ?? $id,
                'owned_by' => $m['owned_by'] ?? '',
            ];
        }

        usort($models, function ($a, $b) {
            return strcmp($a['id'], $b['id']);
        });

        return ['success' => true, 'models' => $models];
    }

    public function saveConfig($apiKey, $baseUrl = null, $model = null, $provider = null)
    {
        $configFile = DATA_PATH . DIRECTORY_SEPARATOR . 'ai_agent.json';
        $existing = [];
        if (file_exists($configFile)) {
            $existing = json_decode(file_get_contents($configFile), true) ?: [];
        }

        if (strpos($apiKey, '*') !== false) {
            $apiKey = $existing['api_key'] ?? '';
        }

        if (empty($apiKey) && $provider !== 'ollama' && $provider !== 'custom') {
            return ['success' => false, 'message' => 'API Key 不能为空'];
        }

        $data = [
            'api_key' => $apiKey,
            'base_url' => $baseUrl ?? $existing['base_url'] ?? 'https://open.bigmodel.cn/api/paas/v4',
            'model' => $model ?? $existing['model'] ?? 'glm-4-flash',
            'provider' => $provider ?? $existing['provider'] ?? 'custom',
            'updated_at' => time(),
        ];

        // Auto-detect max context for the selected model
        $data['max_context'] = $this->resolveMaxContext($data['model']);

        $this->saveAIConfig($data);
        $this->apiKey = $data['api_key'];
        $this->baseUrl = $data['base_url'];
        $this->model = $data['model'];

        return ['success' => true, 'message' => 'AI 配置已保存'];
    }

    public function testConnection($apiKey, $baseUrl)
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'message' => '服务器未安装 curl 扩展'];
        }

        $url = rtrim($baseUrl, '/') . '/models';

        $headers = ['Content-Type: application/json'];
        if (!empty($apiKey)) {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => !$this->isLocalUrl($url),
            CURLOPT_SSL_VERIFYHOST => $this->isLocalUrl($url) ? 0 : 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER => true,
        ]);
        $this->enforceHttpsIfNeeded($ch, $url);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $error = curl_error($ch);
        $dnsTime = curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME);
        $connectTime = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $info = [
            'url' => $url,
            'http_code' => $httpCode,
            'total_time' => round($totalTime, 3) . 's',
            'dns_time' => round($dnsTime, 3) . 's',
            'connect_time' => round($connectTime, 3) . 's',
        ];

        if ($response === false || $error) {
            return [
                'success' => false,
                'message' => "连接失败: {$error}",
                'debug' => $info,
            ];
        }

        if ($httpCode === 0) {
            return [
                'success' => false,
                'message' => '无法连接到服务器（DNS 解析或网络问题）',
                'debug' => $info,
            ];
        }

        $body = $headerSize > 0 ? substr($response, $headerSize) : $response;

        if ($httpCode === 401 || $httpCode === 403) {
            return [
                'success' => false,
                'message' => 'API Key 无效或权限不足 (HTTP ' . $httpCode . ')',
                'debug' => $info,
            ];
        }

        if ($httpCode === 404) {
            return [
                'success' => false,
                'message' => 'API 地址不正确，未找到模型列表接口 (HTTP 404)',
                'debug' => $info,
            ];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $data = json_decode($body, true);
            $modelCount = 0;
            if (isset($data['data']) && is_array($data['data'])) {
                $modelCount = count($data['data']);
            } elseif (isset($data['models']) && is_array($data['models'])) {
                $modelCount = count($data['models']);
            }
            return [
                'success' => true,
                'message' => "连接成功！响应时间 {$info['total_time']}，发现 {$modelCount} 个模型",
                'debug' => $info,
            ];
        }

        return [
            'success' => false,
            'message' => "服务器返回异常状态码 (HTTP {$httpCode})",
            'debug' => array_merge($info, ['response_preview' => substr($body, 0, 300)]),
        ];
    }
}
