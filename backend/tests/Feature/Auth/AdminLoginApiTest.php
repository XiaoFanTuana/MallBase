<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use PHPUnit\Framework\TestCase;

/**
 * 管理员登录接口测试
 */
final class AdminLoginApiTest extends TestCase
{
    /**
     * 登录接口在参数非法时应返回 400
     */
    public function testLoginApiReturnsValidationErrorWhenPayloadIsInvalid(): void
    {
        $response = $this->postJson(
            $this->getBaseUrl() . '/admin/api/auth/admin/login',
            [
                // 小于最小长度 3，触发验证器 login 场景
                'username' => 'ab',
                'password' => '123123',
            ]
        );

        if ($response === null) {
            $this->markTestSkipped('后端接口不可达，请先启动服务后再执行测试。');
        }

        $this->assertIsArray($response);
        $this->assertSame(400, $response['code'] ?? null);
        $this->assertNotEmpty($response['message'] ?? '');
    }

    /**
     * 获取 API 基础地址
     */
    private function getBaseUrl(): string
    {
        $baseUrl = getenv('BACKEND_API_BASE_URL');

        if (!is_string($baseUrl) || trim($baseUrl) === '') {
            return 'http://127.0.0.1:8080';
        }

        return rtrim($baseUrl, '/');
    }

    /**
     * 发送 JSON POST 请求
     *
     * @return array<string, mixed>|null null 表示接口不可达
     */
    private function postJson(string $url, array $payload): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->fail('登录接口响应不是合法 JSON。');
        }

        return $decoded;
    }
}
