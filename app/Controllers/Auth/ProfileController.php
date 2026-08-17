<?php

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Services\AuthService;

class ProfileController extends BaseController
{
    public function changePassword()
    {
        $this->requireAuth();
        $this->validateCSRF();
        $this->rateLimit('change_password', 3, 300);

        $oldPassword = $this->input('old_password', '');
        $newPassword = $this->input('new_password', '');
        $confirmPassword = $this->input('confirm_password', '');

        if ($newPassword !== $confirmPassword) {
            $this->error('两次输入的密码不一致');
        }

        $auth = new AuthService();
        $result = $auth->changePassword($oldPassword, $newPassword);

        if ($result['success']) {
            $this->success($result['message']);
        } else {
            $this->error($result['message']);
        }
    }

    public function updateProfile()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $data = [
            'email' => $this->input('email', ''),
        ];

        $auth = new AuthService();
        $result = $auth->updateProfile($data);

        if ($result['success']) {
            $this->success($result['message']);
        } else {
            $this->error($result['message']);
        }
    }
}
