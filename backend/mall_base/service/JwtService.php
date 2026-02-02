<?php

namespace mall_base\service;

use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key;

/**
 * JWT 服务
 */
class JwtService
{
    /**
     * JWT 密钥
     */
    protected string $key;

    /**
     * Token 过期时间（秒）
     */
    protected int $expire = 7200; // 2小时

    /**
     * 算法
     */
    protected string $algorithm = 'HS256';

    /**
     * 颁发者
     */
    protected string $issuer = 'mall-admin';

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->key = config('jwt.secret') ?: 'your-secret-key-change-in-production';
        $this->expire = config('jwt.expire', 7200);
        $this->algorithm = config('jwt.algorithm', 'HS256');
        $this->issuer = config('jwt.issuer', 'mall-admin');
    }

    /**
     * 生成 Token
     *
     * @param array $payload 载荷数据
     * @return string
     */
    public function encode(array $payload): string
    {
        $now = time();

        $tokenPayload = [
            'iss' => $this->issuer,           // 颁发者
            'iat' => $now,                   // 签发时间
            'nbf' => $now,                   // 生效时间
            'exp' => $now + $this->expire,    // 过期时间
            'data' => $payload,                // 自定义数据
        ];

        return FirebaseJWT::encode($tokenPayload, $this->key, $this->algorithm);
    }

    /**
     * 解析 Token
     *
     * @param string $token Token
     * @return object
     * @throws \Exception
     */
    public function decode(string $token): object
    {
        try {
            $decoded = FirebaseJWT::decode($token, new Key($this->key, $this->algorithm));
            return $decoded;
        } catch (\Exception $e) {
            throw new \Exception('Token 无效或已过期', 401);
        }
    }

    /**
     * 验证 Token
     *
     * @param string $token Token
     * @return bool
     */
    public function verify(string $token): bool
    {
        try {
            $this->decode($token);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 从 Token 中获取用户ID
     *
     * @param string $token Token
     * @return int|null
     */
    public function getUserId(string $token): ?int
    {
        try {
            $decoded = $this->decode($token);
            return $decoded->data->admin_id ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 从 Token 中获取用户名
     *
     * @param string $token Token
     * @return string|null
     */
    public function getUsername(string $token): ?string
    {
        try {
            $decoded = $this->decode($token);
            return $decoded->data->username ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 从 Token 中获取载荷数据
     *
     * @param string $token Token
     * @return object|null
     */
    public function getPayload(string $token): ?object
    {
        try {
            $decoded = $this->decode($token);
            return $decoded->data ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
}