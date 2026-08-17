<?php

namespace App\Services;

use App\Core\Config;

class AdService
{
    private const API_URL = 'https://api.hiyy.top/api/endpoint.php/pancloudad';
    private const CACHE_TTL = 3600;
    private const REQUEST_TIMEOUT = 10;
    private const USER_AGENT = 'YoliArkCloud/Ad-Client';
    private const CACHE_FILENAME = '.ad_cache.json';

    public function isEnabled(): bool
    {
        return (bool) Config::getInstance()->get('ad_enabled', false);
    }

    public function isPromptDismissed(): bool
    {
        return (bool) Config::getInstance()->get('ad_prompt_dismissed', false);
    }

    public function fetchAds(): array
    {
        if (!function_exists('curl_init')) {
            return [];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::API_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        // PHP 8.5 起 curl_close() 已废弃，使用 unset 释放句柄
        unset($ch);

        if ($response === false || $error !== '' || $httpCode !== 200) {
            return [];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return [];
        }

        $ads = [];
        for ($i = 1; $i <= 3; $i++) {
            $img = (string) ($data["ad{$i}img"] ?? '');
            $link = (string) ($data["ad{$i}link"] ?? '');
            if ($img !== '' && $link !== '') {
                $ads[] = ['img' => $img, 'link' => $link];
            }
        }

        return $ads;
    }

    public function getAds(): array
    {
        $cacheFile = $this->getCacheFile();
        $cache = $this->readCache($cacheFile);

        // 缓存未过期：直接使用
        if ($cache !== null && (time() - $cache['timestamp']) < self::CACHE_TTL) {
            return $cache['ads'];
        }

        // 缓存过期：拉取最新广告
        $ads = $this->fetchAds();

        if (!empty($ads)) {
            $this->writeCache($cacheFile, $ads);
            return $ads;
        }

        // API 失败或无可用广告：回退到上次缓存
        if ($cache !== null) {
            return $cache['ads'];
        }

        return [];
    }

    private function getCacheFile(): string
    {
        return DATA_PATH . DIRECTORY_SEPARATOR . self::CACHE_FILENAME;
    }

    private function readCache(string $cacheFile): ?array
    {
        if (!file_exists($cacheFile)) {
            return null;
        }

        $content = file_get_contents($cacheFile);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['timestamp']) || !isset($data['ads']) || !is_array($data['ads'])) {
            return null;
        }

        return $data;
    }

    private function writeCache(string $cacheFile, array $ads): void
    {
        if (!is_dir(DATA_PATH)) {
            mkdir(DATA_PATH, 0755, true);
        }

        $payload = json_encode([
            'timestamp' => time(),
            'ads' => $ads,
        ], JSON_UNESCAPED_UNICODE);

        file_put_contents($cacheFile, $payload, LOCK_EX);
    }
}
