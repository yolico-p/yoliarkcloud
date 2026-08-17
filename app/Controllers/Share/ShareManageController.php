<?php

namespace App\Controllers\Share;

use App\Controllers\BaseController;
use App\Core\Security;

class ShareManageController extends BaseController
{
    public function create()
    {
        $this->requireAuth();
        $this->validateCSRF();
        $this->rateLimit('share', 10, 60);

        $fileId = intval($this->input('file_id', 0));
        if ($fileId <= 0) {
            $this->error('请选择要分享的文件');
        }

        $options = [
            'password' => $this->input('password', ''),
            'max_downloads' => intval($this->input('max_downloads', 0)),
            'expire_days' => intval($this->input('expire_days', 0)),
        ];

        $result = $this->shareService()->createShare($fileId, $options);

        Security::jsonOutput($result);
    }

    public function list()
    {
        $this->requireAuth();

        $page = intval($this->input('page', 1));
        $shares = $this->shareService()->listShares($page);

        Security::jsonOutput(['success' => true, 'shares' => $shares]);
    }

    public function delete()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $shareId = intval($this->input('share_id', 0));
        if ($shareId <= 0) {
            $this->error('参数错误');
        }

        $result = $this->shareService()->deleteShare($shareId);

        Security::jsonOutput($result);
    }

    public function toggle()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $shareId = intval($this->input('share_id', 0));
        if ($shareId <= 0) {
            $this->error('参数错误');
        }

        $result = $this->shareService()->toggleShare($shareId);

        Security::jsonOutput($result);
    }

    public function stats()
    {
        $this->requireAuth();

        $shareId = intval($this->input('share_id', 0));
        if ($shareId <= 0) {
            $this->error('参数错误');
        }

        $result = $this->shareService()->getShareStats($shareId);

        Security::jsonOutput($result);
    }
}
