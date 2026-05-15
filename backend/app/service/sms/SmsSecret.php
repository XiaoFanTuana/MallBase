<?php

declare(strict_types=1);

namespace app\service\sms;

use mall_base\exception\BusinessException;

/**
 * 短信服务商 AccessKeySecret 加解密工具
 *
 * - 算法:AES-256-CBC + base64
 * - 密钥来源:JWT_SECRET(派生 sha256,取前 32 字节)
 * - 密文格式:base64(iv (16B) || ciphertext)
 *
 * 设计原因:
 *  - 数据库泄漏时 AccessKey 不直接暴露
 *  - JWT_SECRET 已在 .env 中保护,不引入新的密钥管理负担
 *  - 不引入新依赖,只用 openssl 扩展(PHP 默认带)
 */
final class SmsSecret
{
    private const CIPHER = 'aes-256-cbc';
    private const IV_LEN = 16;
    private const KEY_BYTES = 32;
    private const CIPHERTEXT_PREFIX = 'enc:v1:';

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        $iv = random_bytes(self::IV_LEN);
        $cipher = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::deriveKey(),
            OPENSSL_RAW_DATA,
            $iv,
        );
        if ($cipher === false) {
            throw new BusinessException('短信凭证加密失败');
        }
        return self::CIPHERTEXT_PREFIX . base64_encode($iv . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        // 兼容明文存量(没有前缀的视为明文,直接返回)
        if (!str_starts_with($stored, self::CIPHERTEXT_PREFIX)) {
            return $stored;
        }
        $raw = base64_decode(substr($stored, strlen(self::CIPHERTEXT_PREFIX)), true);
        if ($raw === false || strlen($raw) <= self::IV_LEN) {
            throw new BusinessException('短信凭证密文格式不正确');
        }
        $iv = substr($raw, 0, self::IV_LEN);
        $cipher = substr($raw, self::IV_LEN);
        $plain = openssl_decrypt(
            $cipher,
            self::CIPHER,
            self::deriveKey(),
            OPENSSL_RAW_DATA,
            $iv,
        );
        if ($plain === false) {
            throw new BusinessException('短信凭证解密失败,JWT_SECRET 可能已变更');
        }
        return $plain;
    }

    /**
     * 判断字符串是否已是加密形态
     */
    public static function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::CIPHERTEXT_PREFIX);
    }

    private static function deriveKey(): string
    {
        $appKey = (string) env('JWT_SECRET', '');
        if ($appKey === '') {
            throw new BusinessException('JWT_SECRET 未配置,无法加密短信凭证');
        }
        return substr(hash('sha256', $appKey, true), 0, self::KEY_BYTES);
    }
}
