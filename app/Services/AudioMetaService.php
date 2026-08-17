<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Models\File;

/**
 * AudioMetaService - 音频预览元数据服务
 *
 * 为音乐播放器提供：
 *  - 同文件夹音频文件列表（播放列表）
 *  - 同文件夹歌词文件匹配
 *  - 封面缩略图 URL
 *  - 文件基础信息
 */
class AudioMetaService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 获取音频文件预览上下文（播放列表、歌词文件、封面等）
     */
    public function getAudioMeta(int $fileId, int $userId): array
    {
        $file = File::find($fileId);
        if (!$file || $file->get('user_id') != $userId) {
            return ['success' => false, 'message' => '文件不存在或无权访问'];
        }

        $ext = strtolower($file->get('file_type', ''));
        $previewService = new PreviewService();
        if (!$previewService->isAudioType($ext)) {
            return ['success' => false, 'message' => '不是音频文件'];
        }

        $parentId = (int) $file->get('parent_id', 0);

        // 同文件夹音频文件（播放列表）
        $siblings = File::findByParent($userId, $parentId, 'filename', 'ASC', 1, 500);
        $audioExts = array_flip(PreviewService::AUDIO_TYPES);
        $playlist = [];
        $lyricFiles = [];
        $currentIndex = 0;

        foreach ($siblings as $item) {
            if ($item->get('is_dir')) {
                continue;
            }
            $itemExt = strtolower($item->get('file_type', ''));
            $itemName = $item->get('filename', '');
            $itemId = (int) $item->get('id');

            if (isset($audioExts[$itemExt])) {
                $playlist[] = [
                    'id' => $itemId,
                    'filename' => $itemName,
                    'filesize' => (int) $item->get('filesize', 0),
                    'filesize_formatted' => Security::formatSize((int) $item->get('filesize', 0)),
                    'file_type' => $itemExt,
                    'mime_type' => $item->get('mime_type', ''),
                    'thumbnail_url' => 'index.php?action=thumbnail&file_id=' . $itemId,
                ];
                if ($itemId === $fileId) {
                    $currentIndex = count($playlist) - 1;
                }
            }

            // 收集歌词文件
            $lowerName = strtolower($itemName);
            if (str_ends_with($lowerName, '.lrc') || str_ends_with($lowerName, '.txt')) {
                $lyricFiles[] = [
                    'id' => $itemId,
                    'filename' => $itemName,
                    'file_type' => $itemExt,
                ];
            }
        }

        return [
            'success' => true,
            'current' => [
                'id' => $fileId,
                'filename' => $file->get('filename'),
                'filesize' => (int) $file->get('filesize', 0),
                'filesize_formatted' => Security::formatSize((int) $file->get('filesize', 0)),
                'file_type' => $ext,
                'mime_type' => $file->get('mime_type', ''),
                'parent_id' => $parentId,
                'thumbnail_url' => 'index.php?action=thumbnail&file_id=' . $fileId,
                'preview_url' => 'index.php?action=preview&file_id=' . $fileId,
            ],
            'current_index' => $currentIndex,
            'playlist' => $playlist,
            'lyric_files' => $lyricFiles,
        ];
    }

    /**
     * 读取歌词文件内容（文本类）
     */
    public function readLyricFile(int $fileId, int $userId): array
    {
        $file = File::find($fileId);
        if (!$file || $file->get('user_id') != $userId) {
            return ['success' => false, 'message' => '文件不存在或无权访问'];
        }

        $ext = strtolower($file->get('file_type', ''));
        if (!in_array($ext, ['lrc', 'txt'], true)) {
            return ['success' => false, 'message' => '不支持的文件类型'];
        }

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file->get('filepath');
        if (!file_exists($fullPath)) {
            return ['success' => false, 'message' => '文件不存在'];
        }

        $content = @file_get_contents($fullPath);
        if ($content === false) {
            return ['success' => false, 'message' => '读取失败'];
        }

        $detectedEncoding = mb_detect_encoding($content, ['UTF-8', 'GB2312', 'GBK', 'GB18030', 'BIG5', 'EUC-CN', 'ISO-8859-1', 'ASCII'], true);
        if ($detectedEncoding === false) {
            $detectedEncoding = 'UTF-8';
        }
        $content = mb_convert_encoding($content, 'UTF-8', $detectedEncoding);

        return [
            'success' => true,
            'content' => $content,
            'filename' => $file->get('filename'),
        ];
    }

    /**
     * 尝试从音频文件内置标签中提取歌词。
     * 支持 MP3(USLT/SYLT)、FLAC/Ogg Vorbis 评论(LYRICS)、M4A(©lyr) 等。
     */
    public function extractEmbeddedLyrics(int $fileId, int $userId): array
    {
        $file = File::find($fileId);
        if (!$file || $file->get('user_id') != $userId) {
            return ['success' => false, 'message' => '文件不存在或无权访问'];
        }

        $ext = strtolower($file->get('file_type', ''));
        $previewService = new PreviewService();
        if (!$previewService->isAudioType($ext)) {
            return ['success' => false, 'message' => '不是音频文件'];
        }

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file->get('filepath');
        if (!\App\Core\Security::isSafeFilePath($fullPath) || !file_exists($fullPath)) {
            return ['success' => false, 'message' => '文件访问被拒绝'];
        }

        if (!class_exists('mutagen\\File', false) && !class_exists('Mutagen\\File', false)) {
            // 尝试自动加载 mutagen（若 composer 未安装则跳过）
            @include_once 'mutagen/File.php';
        }

        $lyrics = '';
        try {
            $lyrics = $this->parseEmbeddedLyrics($fullPath, $ext);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => '解析失败: ' . $e->getMessage()];
        }

        if ($lyrics === '') {
            return ['success' => true, 'content' => '', 'embedded' => false];
        }

        return [
            'success' => true,
            'content' => $lyrics,
            'embedded' => true,
        ];
    }

    /**
     * 使用 mutagen 解析不同格式的内置歌词。
     */
    protected function parseEmbeddedLyrics(string $fullPath, string $ext): string
    {
        if (!class_exists('mutagen\\File', false)) {
            // 尝试通过 Python 命令行调用 mutagen（服务器已安装 Python + mutagen 时可用）
            return $this->parseEmbeddedLyricsViaPython($fullPath, $ext);
        }

        // PHP 原生 mutagen 扩展（极少见），这里仅作占位
        return '';
    }

    /**
     * 通过 Python 子进程调用 mutagen 读取内置歌词。
     * 避免在 PHP 中重复实现各格式解析逻辑。
     */
    protected function parseEmbeddedLyricsViaPython(string $fullPath, string $ext): string
    {
        $pythonScript = <<<'PY'
import sys

# 保证 stdout 用 UTF-8 且不换行为 \r\n，避免 Windows 默认编码截断中文
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', newline='\n')
if hasattr(sys.stderr, 'reconfigure'):
    sys.stderr.reconfigure(encoding='utf-8', newline='\n')

path = sys.argv[1]
ext = sys.argv[2].lower()
lyrics = ""

def first_lyric(tags, keys):
    for k in keys:
        vals = tags.get(k)
        if vals:
            return str(vals[0])
    return ""

def sylt_to_lrc(sylt):
    lines = []
    for t, text in getattr(sylt, 'text', []):
        ms = int(t)
        m = ms // 60000
        s = (ms % 60000) // 1000
        ms_rem = (ms % 1000) // 10
        lines.append(f"[{m:02d}:{s:02d}.{ms_rem:02d}]{text}")
    return "\n".join(lines)

try:
    if ext in ('mp3', 'mp2', 'mp1'):
        from mutagen.mp3 import MP3
        audio = MP3(path)
        tags = audio.tags
        if tags:
            # USLT 在 mutagen 中的键可能是 'USLT' 或 'USLT::语言代码'
            for k in tags.keys():
                if k.startswith('USLT'):
                    val = tags[k]
                    if isinstance(val, list):
                        for item in val:
                            if getattr(item, 'text', ''):
                                lyrics = item.text
                                break
                    else:
                        lyrics = getattr(val, 'text', '')
                    if lyrics:
                        break
            if not lyrics:
                for k in tags.keys():
                    if k.startswith('SYLT'):
                        val = tags[k]
                        if isinstance(val, list):
                            val = val[0]
                        lyrics = sylt_to_lrc(val)
                        if lyrics:
                            break
    elif ext == 'flac':
        from mutagen.flac import FLAC
        audio = FLAC(path)
        lyrics = first_lyric(audio, (
            'LYRICS', 'lyrics', 'UNSYNCEDLYRICS', 'unsyncedlyrics',
            'UNSYNCED LYRICS', 'SYNCEDLYRICS', 'SYNCED LYRICS',
        ))
        # 若常规键名未命中，扫描所有含 lyric 的键
        if not lyrics:
            for k in audio.keys():
                if 'lyric' in k.lower():
                    lyrics = str(audio[k][0])
                    break
    elif ext in ('ogg', 'oga'):
        from mutagen.oggvorbis import OggVorbis
        audio = OggVorbis(path)
        lyrics = first_lyric(audio, (
            'LYRICS', 'lyrics', 'UNSYNCEDLYRICS', 'unsyncedlyrics',
            'UNSYNCED LYRICS', 'SYNCEDLYRICS', 'SYNCED LYRICS',
        ))
        if not lyrics:
            for k in audio.keys():
                if 'lyric' in k.lower():
                    lyrics = str(audio[k][0])
                    break
    elif ext in ('m4a', 'm4b', 'm4p', 'mp4', 'aac'):
        from mutagen.mp4 import MP4
        audio = MP4(path)
        tags = audio.tags
        if tags:
            lyrics = first_lyric(tags, ('\u00a9lyr', 'lyr', 'LYRICS', 'lyrics'))
    elif ext in ('wma', 'asf'):
        from mutagen.asf import ASF
        audio = ASF(path)
        lyrics = first_lyric(audio, ('WM/Lyrics', 'WM/LyricsSync', 'LYRICS', 'lyrics'))
    elif ext == 'wav':
        from mutagen.wave import WAVE
        audio = WAVE(path)
        tags = audio.tags
        if tags:
            for k in tags.keys():
                if k.startswith('USLT'):
                    val = tags[k]
                    if isinstance(val, list):
                        for item in val:
                            if getattr(item, 'text', ''):
                                lyrics = item.text
                                break
                    else:
                        lyrics = getattr(val, 'text', '')
                    if lyrics:
                        break
except Exception as e:
    print(str(e), file=sys.stderr)

# 统一换行符并输出
if lyrics:
    lyrics = lyrics.replace('\r\n', '\n').replace('\r', '\n')
print(lyrics)
PY;

        $tempScript = tempnam(sys_get_temp_dir(), 'lyr_');
        file_put_contents($tempScript, $pythonScript);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // 依次尝试常见 Python 命令
        $pythonBinaries = ['python', 'python3', 'py'];
        $output = '';
        $lastError = '';

        foreach ($pythonBinaries as $py) {
            $cmd = escapeshellarg($py) . ' ' . escapeshellarg($tempScript) . ' ' . escapeshellarg($fullPath) . ' ' . escapeshellarg($ext);
            $process = proc_open($cmd, $descriptorSpec, $pipes);
            if (!is_resource($process)) {
                continue;
            }

            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode === 0 && trim($output) !== '') {
                break;
            }
            if ($error !== '') {
                $lastError = $error;
            }
        }

        @unlink($tempScript);

        $output = trim($output);
        if ($output !== '') {
            return $output;
        }

        return '';
    }
}
