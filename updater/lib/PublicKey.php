<?php

namespace Updater;

/**
 * 内置 Ed25519 公钥（部署时替换常量值为真实公钥的十六进制字符串）。
 */
class PublicKey
{
    /**
     * Ed25519 公钥（hex 字符串，32 字节 / 64 字符）。
     *
     * 来源：https://yoliarkupdate.yoliark.com/public-key.pem
     * 从 SPKI PEM 中提取的原始 32 字节公钥，硬编码以防中间人攻击（规范 14.5）。
     */
    public const ED25519_PUBLIC_KEY = 'ac4a2dcc221f7099733692abf3dbea0128dbdd336edf2c0e9583a95118081bc4';

    /**
     * 返回二进制公钥（32 字节）。
     *
     * @return string
     * @throws \RuntimeException 当公钥未配置或长度不合法时
     */
    public static function getPublicKey(): string
    {
        $hex = self::ED25519_PUBLIC_KEY;
        if ($hex === '') {
            throw new \RuntimeException('Ed25519 public key not configured.');
        }
        $bin = @hex2bin($hex);
        if ($bin === false || strlen($bin) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \RuntimeException('Ed25519 public key has invalid length.');
        }
        return $bin;
    }
}
