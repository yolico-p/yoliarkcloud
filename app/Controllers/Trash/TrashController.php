<?php

namespace App\Controllers\Trash;

use App\Controllers\BaseController;
use App\Core\Security;

class TrashController extends BaseController
{
    public function list()
    {
        $this->requireAuth();

        $items = $this->trashService()->listTrash();

        Security::jsonOutput(['success' => true, 'items' => $items]);
    }

    public function restore()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $trashId = intval($this->input('trash_id', 0));
        if ($trashId <= 0) {
            $this->error('参数错误');
        }

        $result = $this->trashService()->restore($trashId);

        Security::jsonOutput($result);
    }

    public function permanentDelete()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $trashId = intval($this->input('trash_id', 0));
        if ($trashId <= 0) {
            $this->error('参数错误');
        }

        $result = $this->trashService()->permanentDelete($trashId);

        Security::jsonOutput($result);
    }

    public function emptyTrash()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $result = $this->trashService()->emptyTrash();

        Security::jsonOutput($result);
    }
}
