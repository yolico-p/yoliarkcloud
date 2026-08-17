<?php

namespace App\Controllers\System;

use App\Controllers\BaseController;
use App\Core\Security;
use App\Core\Config;
use App\Services\AdService;

class AdController extends BaseController
{
    public function getConfig()
    {
        $this->requireAdmin();
        $this->validateCSRF();

        $config = Config::getInstance();

        Security::jsonOutput([
            'success' => true,
            'ad_enabled' => (bool) $config->get('ad_enabled', false),
            'ad_prompt_dismissed' => (bool) $config->get('ad_prompt_dismissed', false),
        ]);
    }

    public function toggleEnabled()
    {
        $this->requireAdmin();
        $this->validateCSRF();

        $enabled = (bool) $this->input('enabled', false);

        $config = Config::getInstance();
        $config->set('ad_enabled', $enabled);
        $result = $config->save();

        if (!$result) {
            $this->error('配置保存失败');
        }

        Security::jsonOutput([
            'success' => true,
            'ad_enabled' => $enabled,
        ]);
    }

    public function dismissPrompt()
    {
        $this->requireAdmin();
        $this->validateCSRF();

        $config = Config::getInstance();
        $config->set('ad_prompt_dismissed', true);
        $result = $config->save();

        if (!$result) {
            $this->error('配置保存失败');
        }

        Security::jsonOutput(['success' => true]);
    }

    public function getAds()
    {
        $adService = new AdService();

        $ads = [];
        if ($adService->isEnabled()) {
            $ads = $adService->getAds();
        }

        Security::jsonOutput([
            'success' => true,
            'ads' => $ads,
        ]);
    }
}
