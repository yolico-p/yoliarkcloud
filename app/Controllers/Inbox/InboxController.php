<?php

namespace App\Controllers\Inbox;

use App\Controllers\BaseController;
use App\Core\Security;
use App\Core\Config;
use App\Services\InboxService;
use App\Support\HttpRangeTrait;

class InboxController extends BaseController
{
    use HttpRangeTrait;

    private InboxService $inboxService;

    public function __construct()
    {
        parent::__construct();
        $this->inboxService = new InboxService();
    }

    // Get inbox info (auth required) - action: inbox_info
    public function info()
    {
        $userId = $this->getUserId();
        $result = $this->inboxService->getInboxInfo($userId);
        $this->json($result);
    }

    // Toggle inbox enabled (auth required) - action: inbox_toggle
    public function toggle()
    {
        $enabled = (bool) $this->input('enabled', false);
        $result = $this->inboxService->toggleInbox($enabled);
        $this->json($result);
    }

    // Regenerate inbox URL (auth required) - action: inbox_regenerate
    public function regenerate()
    {
        $result = $this->inboxService->regenerateUrl();
        $this->json($result);
    }

    // Download inbox file (auth required) - action: inbox_download
    public function download()
    {
        $fileId = (int) $this->input('file_id', 0);
        if ($fileId <= 0) {
            $this->error('参数无效');
        }

        $userId = $this->getUserId();
        $result = $this->inboxService->downloadFile($fileId, $userId);

        if (!$result['success']) {
            $this->error($result['message']);
        }

        $fullPath = $result['path'];
        $filename = $result['filename'];
        $mimeType = $result['mime'];
        $fileSize = $result['size'];

        if (!file_exists($fullPath)) {
            $this->error('文件不存在');
        }

        $this->sendFileWithRange($fullPath, $filename, $fileSize, $mimeType, '', false);
    }

    // Move inbox file to filesystem (auth required) - action: inbox_move
    public function move()
    {
        $fileId = (int) $this->input('file_id', 0);
        $targetParentId = (int) $this->input('target_parent_id', 0);

        if ($fileId <= 0) {
            $this->error('参数无效');
        }

        $userId = $this->getUserId();
        $result = $this->inboxService->moveToFilesystem($fileId, $targetParentId, $userId);

        if ($result['success']) {
            $this->success($result['message']);
        } else {
            $this->error($result['message']);
        }
    }

    // Delete inbox file (auth required) - action: inbox_delete
    public function delete()
    {
        $fileId = (int) $this->input('file_id', 0);
        if ($fileId <= 0) {
            $this->error('参数无效');
        }

        $userId = $this->getUserId();
        $result = $this->inboxService->deleteFile($fileId, $userId);

        if ($result['success']) {
            $this->success($result['message']);
        } else {
            $this->error($result['message']);
        }
    }

    // Public: upload file to inbox (NO auth required) - action: inbox_upload
    public function upload()
    {
        $this->rateLimit('inbox_upload', 5, 60);

        $token = $this->input('inbox_token', '');
        if (empty($token)) {
            $this->error('缺少收件链接参数', 400);
        }

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->error('请选择要投递的文件');
        }

        $senderName = $this->input('sender_name', '');
        $senderMessage = $this->input('sender_message', '');

        $result = $this->inboxService->uploadToInbox($token, $_FILES['file'], $senderName, $senderMessage);

        if ($result['success']) {
            $this->success($result['message']);
        } else {
            $this->error($result['message']);
        }
    }

    // Public: verify inbox token (NO auth required) - action: inbox_verify
    public function verify()
    {
        $token = $this->input('token', '');
        if (empty($token)) {
            $this->json(['valid' => false, 'message' => '缺少收件链接参数']);
        }

        $result = $this->inboxService->verifyToken($token);
        $this->json($result);
    }
}
