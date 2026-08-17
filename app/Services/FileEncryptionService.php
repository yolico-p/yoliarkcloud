<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Security;

class FileEncryptionService
{
    /**
     * 加密文件格式版本标识。
     *
     * - 'YA1'：AES-256-GCM 分块流式（当前默认）。文件头 = magic(3B) + 原大小(8B BE)，
     *   随后每块 = nonce(12B) + ciphertext(N) + tag(16B)，每块独立认证。
     * - 旧文件无 magic：开头直接是 16B IV + AES-256-CBC 密文，解密时按旧逻辑兼容。
     */
    const ENC_MAGIC       = 'YA1';
    const ENC_GCM_CHUNK   = 65536;   // 64KB 明文/块
    const ENC_GCM_NONCE   = 12;
    const ENC_GCM_TAG     = 16;
    const ENC_CBC_IV_LEN  = 16;

    private $db;
    private $auth;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->auth = new AuthService();
    }

    public function encryptFile($fileId)
    {
        $userId = $this->auth->getUserId();
        $encKey = $this->auth->getEncryptionKey();
        if (!$encKey) return ['success' => false, 'message' => '加密密钥不可用，请重新登录'];
        if (strlen($encKey) < 32) return ['success' => false, 'message' => '加密密钥长度不足'];

        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);
        if (!$file) return ['success' => false, 'message' => '文件不存在'];
        if ($file['is_dir']) return ['success' => false, 'message' => '文件夹不支持加密'];
        if (!empty($file['is_encrypted'])) return ['success' => false, 'message' => '文件已加密'];

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];
        if (!file_exists($fullPath)) return ['success' => false, 'message' => '文件不存在'];

        $tmpPath = $fullPath . '.enc_tmp';
        $fpIn = fopen($fullPath, 'rb');
        if ($fpIn === false) return ['success' => false, 'message' => '读取文件失败'];
        $fpOut = fopen($tmpPath, 'wb');
        if ($fpOut === false) {
            fclose($fpIn);
            return ['success' => false, 'message' => '创建临时文件失败'];
        }

        try {
            $originalSize = filesize($fullPath);
            $header = self::ENC_MAGIC . pack('J', $originalSize);
            if (fwrite($fpOut, $header) === false) {
                @unlink($tmpPath);
                return ['success' => false, 'message' => '写入文件头失败'];
            }

            while (!feof($fpIn)) {
                $chunk = fread($fpIn, self::ENC_GCM_CHUNK);
                if ($chunk === false) break;
                if ($chunk === '') continue;

                $nonce = random_bytes(self::ENC_GCM_NONCE);
                $tag = '';
                $ciphertext = openssl_encrypt(
                    $chunk, 'AES-256-GCM', $encKey, OPENSSL_RAW_DATA, $nonce, $tag
                );
                if ($ciphertext === false) {
                    @unlink($tmpPath);
                    return ['success' => false, 'message' => '加密失败'];
                }
                fwrite($fpOut, $nonce);
                fwrite($fpOut, $ciphertext);
                fwrite($fpOut, $tag);
            }
        } finally {
            fclose($fpIn);
            fclose($fpOut);
        }

        if (!rename($tmpPath, $fullPath)) {
            @unlink($tmpPath);
            return ['success' => false, 'message' => '写入加密文件失败'];
        }

        $this->db->update('files', [
            'is_encrypted' => 1,
            'updated_at' => time(),
        ], 'id = ? AND user_id = ?', [$fileId, $userId]);

        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => '文件已加密'];
    }

    public function decryptFile($fileId)
    {
        $userId = $this->auth->getUserId();
        $encKey = $this->auth->getEncryptionKey();
        if (!$encKey) return ['success' => false, 'message' => '加密密钥不可用，请重新登录'];
        if (strlen($encKey) < 32) return ['success' => false, 'message' => '加密密钥长度不足'];

        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);
        if (!$file) return ['success' => false, 'message' => '文件不存在'];
        if (empty($file['is_encrypted'])) return ['success' => false, 'message' => '文件未加密'];

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];
        if (!file_exists($fullPath)) return ['success' => false, 'message' => '文件不存在'];

        $tmpPath = $fullPath . '.dec_tmp';
        $fpIn = fopen($fullPath, 'rb');
        if ($fpIn === false) return ['success' => false, 'message' => '读取文件失败'];
        $fpOut = fopen($tmpPath, 'wb');
        if ($fpOut === false) {
            fclose($fpIn);
            return ['success' => false, 'message' => '创建临时文件失败'];
        }

        $decryptResult = null;
        try {
            $magic = $this->readExact($fpIn, 3);
            $isGcm = ($magic === self::ENC_MAGIC);

            if ($isGcm) {
                $sizeBytes = $this->readExact($fpIn, 8);
                if (strlen($sizeBytes) < 8) {
                    $decryptResult = '文件头已损坏';
                } else {
                    $originalSize = unpack('J', $sizeBytes)[1];
                    if ($originalSize < 0) {
                        $decryptResult = '文件头已损坏';
                    } else {
                        $decryptResult = $this->decryptGcmStream($fpIn, $fpOut, $encKey, $originalSize);
                    }
                }
            } else {
                fseek($fpIn, 0);
                $iv = $this->readExact($fpIn, self::ENC_CBC_IV_LEN);
                if (strlen($iv) < self::ENC_CBC_IV_LEN) {
                    $decryptResult = '读取文件失败';
                } else {
                    $decryptResult = $this->decryptCbcStream($fpIn, $fpOut, $encKey, $iv);
                }
            }
        } finally {
            fclose($fpIn);
            fclose($fpOut);
        }

        if ($decryptResult !== true) {
            @unlink($tmpPath);
            return ['success' => false, 'message' => $decryptResult];
        }

        if (!rename($tmpPath, $fullPath)) {
            @unlink($tmpPath);
            return ['success' => false, 'message' => '写入解密文件失败'];
        }

        $newSize = filesize($fullPath);

        $this->db->update('files', [
            'is_encrypted' => 0,
            'filesize' => $newSize,
            'updated_at' => time(),
        ], 'id = ? AND user_id = ?', [$fileId, $userId]);

        $this->db->invalidateTableCache("files");

        return ['success' => true, 'message' => '文件已解密'];
    }

    public function decryptFileToTemp($fileId)
    {
        $encKey = $this->auth->getEncryptionKey();
        if (!$encKey) return null;
        if (strlen($encKey) < 32) return null;

        $file = $this->db->fetch("SELECT * FROM files WHERE id = ? AND user_id = ?", [$fileId, $this->auth->getUserId()]);
        if (!$file || empty($file['is_encrypted'])) return null;

        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];
        if (!file_exists($fullPath)) return null;

        $fpIn = fopen($fullPath, 'rb');
        if ($fpIn === false) return null;

        $tempPath = UPLOAD_PATH . DIRECTORY_SEPARATOR . 'dec_' . $fileId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $file['file_type'];
        $fpOut = fopen($tempPath, 'wb');
        if ($fpOut === false) {
            fclose($fpIn);
            return null;
        }

        $result = null;
        try {
            $magic = $this->readExact($fpIn, 3);
            if ($magic === self::ENC_MAGIC) {
                $sizeBytes = $this->readExact($fpIn, 8);
                if (strlen($sizeBytes) < 8) {
                    $result = '文件头已损坏';
                } else {
                    $originalSize = unpack('J', $sizeBytes)[1];
                    if ($originalSize < 0) {
                        $result = '文件头已损坏';
                    } else {
                        $result = $this->decryptGcmStream($fpIn, $fpOut, $encKey, $originalSize);
                    }
                }
            } else {
                fseek($fpIn, 0);
                $iv = $this->readExact($fpIn, self::ENC_CBC_IV_LEN);
                if (strlen($iv) < self::ENC_CBC_IV_LEN) {
                    $result = '读取文件失败';
                } else {
                    $result = $this->decryptCbcStream($fpIn, $fpOut, $encKey, $iv);
                }
            }
        } finally {
            fclose($fpIn);
            fclose($fpOut);
        }

        if ($result !== true) {
            @unlink($tempPath);
            return null;
        }

        $newSize = filesize($tempPath);
        return ['path' => $tempPath, 'size' => $newSize, 'temp' => true];
    }

    private function readExact($fp, int $len): string
    {
        $data = '';
        while (strlen($data) < $len && !feof($fp)) {
            $chunk = fread($fp, $len - strlen($data));
            if ($chunk === false) break;
            $data .= $chunk;
        }
        return $data;
    }

    /**
     * GCM 分块流式解密。每块布局：nonce(12B) + ciphertext(N) + tag(16B)。
     * 通过文件头中的原大小精确计算每块明文长度，从而读取正确长度的密文，
     * 避免最后一块因固定读取 ENC_GCM_CHUNK 而误把 tag 读进密文。
     *
     * @param int $originalSize 文件头中记录的原文件明文大小
     * @return true|string 成功返回 true，失败返回错误信息字符串
     */
    private function decryptGcmStream($fpIn, $fpOut, string $encKey, int $originalSize)
    {
        $decryptedSize = 0;
        while (!feof($fpIn)) {
            $nonce = $this->readExact($fpIn, self::ENC_GCM_NONCE);
            if (strlen($nonce) < self::ENC_GCM_NONCE) {
                break;
            }

            $remaining = $originalSize - $decryptedSize;
            if ($remaining <= 0) {
                break;
            }
            $chunkPlainLen = min($remaining, self::ENC_GCM_CHUNK);

            $ciphertext = $this->readExact($fpIn, $chunkPlainLen);
            if (strlen($ciphertext) < $chunkPlainLen) {
                return '解密失败：密文不完整';
            }

            $tag = $this->readExact($fpIn, self::ENC_GCM_TAG);
            if (strlen($tag) < self::ENC_GCM_TAG) {
                return '解密失败：密文不完整';
            }

            $decrypted = openssl_decrypt(
                $ciphertext, 'AES-256-GCM', $encKey, OPENSSL_RAW_DATA, $nonce, $tag
            );
            if ($decrypted === false) {
                return '解密失败，密钥可能不正确或文件已损坏';
            }
            fwrite($fpOut, $decrypted);
            $decryptedSize += strlen($decrypted);
        }

        if ($decryptedSize !== $originalSize) {
            return '解密失败：文件大小校验不一致';
        }
        return true;
    }

    /**
     * CBC 流式解密（兼容旧版加密文件）。
     * 每轮 IV 为上一块密文最后 16 字节，使用 ZERO_PADDING 处理中间块，
     * 最后一块用默认 PKCS7 padding。
     *
     * @return true|string
     */
    private function decryptCbcStream($fpIn, $fpOut, string $encKey, string $iv)
    {
        $blockSize = self::ENC_CBC_IV_LEN;
        $readSize = (int) (floor(65536 / $blockSize) * $blockSize);

        while (!feof($fpIn)) {
            $chunk = fread($fpIn, $readSize);
            if ($chunk === false) break;
            if ($chunk === '') continue;

            $isLastChunk = feof($fpIn);
            $options = $isLastChunk ? OPENSSL_RAW_DATA : (OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);

            $decrypted = openssl_decrypt($chunk, 'AES-256-CBC', $encKey, $options, $iv);
            if ($decrypted === false) {
                return '解密失败，密钥可能不正确';
            }
            fwrite($fpOut, $decrypted);
            $iv = substr($chunk, -$blockSize);
        }
        return true;
    }
}
