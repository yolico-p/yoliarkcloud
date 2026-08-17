<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Security;
use App\Core\Config;
use App\Core\Request;
use App\Core\Middleware;
use App\Services\AuthService;
use App\Services\FileManagerService;
use App\Services\ShareService;
use App\Services\TrashService;
use App\Services\ThumbnailService;
use App\Services\AudioCoverService;

abstract class BaseController
{
    protected $db;
    protected $config;
    protected $userId;
    protected $auth;
    protected $fileManager;
    protected $shareService;
    protected $trashService;
    protected $request;
    protected $encryptionService;
    protected $favoriteService;
    protected $queryService;
    protected $accessService;
    protected $uploadService;
    protected $aiConfigService;
    protected $aiSessionService;
    protected $aiToolService;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = Config::getInstance();
        $this->request = Request::current();
        $this->userId = $_SESSION['user_id'] ?? null;
    }

    protected function auth()
    {
        if ($this->auth === null) {
            $this->auth = new AuthService();
        }
        return $this->auth;
    }

    protected function fileManager()
    {
        if ($this->fileManager === null) {
            $this->fileManager = new FileManagerService();
        }
        return $this->fileManager;
    }

    protected function shareService()
    {
        if ($this->shareService === null) {
            $this->shareService = new ShareService();
        }
        return $this->shareService;
    }

    protected function trashService()
    {
        if ($this->trashService === null) {
            $this->trashService = new TrashService();
        }
        return $this->trashService;
    }

    protected function encryptionService()
    {
        if ($this->encryptionService === null) {
            $this->encryptionService = new \App\Services\FileEncryptionService();
        }
        return $this->encryptionService;
    }

    protected function favoriteService()
    {
        if ($this->favoriteService === null) {
            $this->favoriteService = new \App\Services\FileFavoriteService();
        }
        return $this->favoriteService;
    }

    protected function queryService()
    {
        if ($this->queryService === null) {
            $this->queryService = new \App\Services\FileQueryService();
        }
        return $this->queryService;
    }

    protected function accessService()
    {
        if ($this->accessService === null) {
            $this->accessService = new \App\Services\FileAccessService();
        }
        return $this->accessService;
    }

    protected function uploadService()
    {
        if ($this->uploadService === null) {
            $this->uploadService = new \App\Services\UploadService($this->fileManager());
        }
        return $this->uploadService;
    }

    protected function aiConfigService()
    {
        if ($this->aiConfigService === null) {
            $this->aiConfigService = new \App\Services\AIConfigService();
        }
        return $this->aiConfigService;
    }

    protected function aiSessionService()
    {
        if ($this->aiSessionService === null) {
            $this->aiSessionService = new \App\Services\AISessionService();
        }
        return $this->aiSessionService;
    }

    protected function aiToolService()
    {
        if ($this->aiToolService === null) {
            $this->aiToolService = new \App\Services\AIToolService();
        }
        return $this->aiToolService;
    }

    /**
     * 获取当前请求对象（封装超全局变量，提升可测试性）。
     */
    protected function request(): Request
    {
        return $this->request;
    }

    protected function getUserId()
    {
        if (!$this->userId) {
            $this->unauthorized('请先登录');
        }
        return $this->userId;
    }

    protected function requireAuth()
    {
        // 走完整 AuthService 校验链（指纹/过期/会话固定防护）
        Middleware::auth();
        $this->userId = $_SESSION['user_id'] ?? null;
        return $this;
    }

    protected function requireAdmin()
    {
        Middleware::admin();
        return $this;
    }

    protected function success($message = '操作成功', $data = [])
    {
        Security::jsonOutput(array_merge(['success' => true, 'message' => $message], $data));
    }

    protected function error($message = '操作失败', $status = 400)
    {
        Security::jsonOutput(['success' => false, 'message' => $message], $status);
    }

    protected function json($data, $status = 200)
    {
        Security::jsonOutput($data, $status);
    }

    protected function unauthorized($message = '未授权')
    {
        Security::jsonOutput(['success' => false, 'message' => $message], 401);
    }

    /**
     * 统一限流入口（基于数据库表，替代文件 flock）。
     */
    protected function rateLimit($action, $maxAttempts, $decaySeconds)
    {
        Middleware::rateLimit($action, $maxAttempts, $decaySeconds, $this->request);
    }

    protected function adaptiveRateLimit($action, $fileSize = 0)
    {
        Middleware::adaptiveRateLimit($action, $fileSize);
    }

    /**
     * 软限流：仅扣减令牌并记录，不拒绝请求。
     * 适用于分片上传等不应因限流而中断的场景。
     */
    protected function adaptiveRateLimitSoft($action, $fileSize = 0)
    {
        Middleware::adaptiveRateLimitSoft($action, $fileSize);
    }

    /**
     * CSRF 校验 — 委托 Middleware::csrf()，统一入口。
     */
    protected function validateCSRF()
    {
        Middleware::csrf($this->request);
    }

    /**
     * 输入取值 — 委托 Request 对象，避免直接读取超全局变量。
     */
    protected function input($key, $default = null)
    {
        return $this->request->input($key, $default);
    }

    /**
     * 清除所有输出缓冲层，用于大文件二进制下载前调用。
     * 避免 Security::init() 注册的压缩 ob_start 缓冲二进制流。
     */
    protected static function cleanOutputBuffer()
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    /**
     * 解析批量操作的 ID 列表参数，统一转为 int[]。
     * 支持 JSON 字符串和数组两种传入方式。
     */
    protected function parseIdList($paramName)
    {
        return $this->request->idList($paramName);
    }
}

