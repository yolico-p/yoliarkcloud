<?php

namespace App\Controllers\File;

use App\Controllers\BaseController;
use App\Services\AudioMetaService;

class AudioMetaController extends BaseController
{
    private AudioMetaService $audioMetaService;

    public function __construct()
    {
        parent::__construct();
        $this->audioMetaService = new AudioMetaService();
    }

    /**
     * action=audio_meta
     * 返回音频文件的播放列表上下文、歌词文件列表、封面等
     */
    public function meta()
    {
        $fileId = (int) $this->input('file_id', 0);
        if ($fileId <= 0) {
            $this->error('参数无效');
        }

        $userId = $this->getUserId();
        $result = $this->audioMetaService->getAudioMeta($fileId, $userId);

        if ($result['success']) {
            $this->success('', $result);
        } else {
            $this->error($result['message']);
        }
    }

    /**
     * action=audio_lyric
     * 读取指定歌词文件内容
     */
    public function lyric()
    {
        $fileId = (int) $this->input('file_id', 0);
        if ($fileId <= 0) {
            $this->error('参数无效');
        }

        $userId = $this->getUserId();
        $result = $this->audioMetaService->readLyricFile($fileId, $userId);

        if ($result['success']) {
            $this->success('', ['content' => $result['content'], 'filename' => $result['filename']]);
        } else {
            $this->error($result['message']);
        }
    }

    /**
     * action=audio_embedded_lyric
     * 从音频文件内置标签中提取歌词（MP3 USLT/SYLT、FLAC LYRICS、M4A ©lyr 等）
     */
    public function embeddedLyric()
    {
        $fileId = (int) $this->input('file_id', 0);
        if ($fileId <= 0) {
            $this->error('参数无效');
        }

        $userId = $this->getUserId();
        $result = $this->audioMetaService->extractEmbeddedLyrics($fileId, $userId);

        if ($result['success']) {
            $this->success('', ['content' => $result['content'] ?? '', 'embedded' => !empty($result['embedded'])]);
        } else {
            $this->error($result['message']);
        }
    }
}
