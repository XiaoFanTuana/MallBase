<?php

declare(strict_types=1);

namespace Tests\Feature\Setting;

use PHPUnit\Framework\TestCase;

final class SettingRuleAcceptTypesCompatTest extends TestCase
{
    public function testAcceptTypesRulePersistsMimeValuesAfterUpdateAndRead(): void
    {
        $token = $this->loginAndGetToken();
        if ($token === null) {
            $this->markTestSkipped('登录失败或接口不可达，跳过设置项兼容性测试。');
        }

        $listResponse = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/admin/api/setting/item/list',
            ['page' => 1, 'limit' => 20],
            ["Authorization: Bearer {$token}"]
        );

        if ($listResponse === null) {
            $this->markTestSkipped('设置项列表接口不可达，跳过测试。');
        }

        $this->assertSame(200, $listResponse['code'] ?? null);
        $listData = $listResponse['data'] ?? [];
        $list = is_array($listData) ? ($listData['list'] ?? []) : [];
        $this->assertIsArray($list);
        if (empty($list)) {
            $this->markTestSkipped('设置项列表为空，无法执行更新回读兼容测试。');
        }

        $target = $this->pickTargetItem($list);
        if (!is_array($target)) {
            $this->markTestSkipped('未找到可编辑的设置项。');
        }

        $itemId = (int)($target['id'] ?? 0);
        $this->assertGreaterThan(0, $itemId);

        $originalPayload = $this->buildUpdatePayload($target);
        $testRules = [
            [
                'type' => 'accept_types',
                'value' => ['application/pdf', 'video/mp4'],
                'message' => '支持的文件类型:application/pdf,video/mp4',
            ],
        ];

        $testPayload = $originalPayload;
        $testPayload['type'] = 'file';
        $testPayload['rules'] = $testRules;

        try {
            $updateResponse = $this->requestJson(
                'PUT',
                $this->getBaseUrl() . '/admin/api/setting/item/update/' . $itemId,
                $testPayload,
                ["Authorization: Bearer {$token}"]
            );

            $this->assertIsArray($updateResponse);
            $this->assertSame(200, $updateResponse['code'] ?? null, '更新设置项失败，无法验证回读兼容性');

            $updated = $this->fetchItemById($itemId, $token);
            $this->assertIsArray($updated, '更新后未能读取到设置项');

            $rules = $updated['rules'] ?? [];
            $this->assertIsArray($rules);
            $acceptRule = $this->findRuleByType($rules, 'accept_types');
            $this->assertIsArray($acceptRule, '回读规则中缺少 accept_types');

            $value = $acceptRule['value'] ?? null;
            $this->assertIsArray($value, 'accept_types.value 应保持 MIME 数组');
            $this->assertContains('application/pdf', $value);
            $this->assertContains('video/mp4', $value);

            foreach ($value as $v) {
                $this->assertIsString($v);
                $this->assertStringNotContainsString('文档', $v, 'accept_types.value 不应写入展示文案');
                $this->assertStringNotContainsString('视频', $v, 'accept_types.value 不应写入展示文案');
            }
        } finally {
            $this->requestJson(
                'PUT',
                $this->getBaseUrl() . '/admin/api/setting/item/update/' . $itemId,
                $originalPayload,
                ["Authorization: Bearer {$token}"]
            );
        }
    }

    private function pickTargetItem(array $list): ?array
    {
        foreach ($list as $item) {
            if (is_array($item) && isset($item['id'], $item['name'], $item['code'])) {
                return $item;
            }
        }

        return null;
    }

    private function buildUpdatePayload(array $item): array
    {
        return [
            'group_id' => (int)($item['group_id'] ?? 0),
            'name' => (string)($item['name'] ?? ''),
            'code' => (string)($item['code'] ?? ''),
            'value' => (string)($item['value'] ?? ''),
            'type' => (string)($item['type'] ?? 'input'),
            'options' => $item['options'] ?? null,
            'rules' => is_array($item['rules'] ?? null) ? $item['rules'] : null,
            'placeholder' => (string)($item['placeholder'] ?? ''),
            'remark' => (string)($item['remark'] ?? ''),
            'sort' => (int)($item['sort'] ?? 0),
        ];
    }

    private function fetchItemById(int $itemId, string $token): ?array
    {
        $response = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/admin/api/setting/item/list',
            ['page' => 1, 'limit' => 50],
            ["Authorization: Bearer {$token}"]
        );

        if (!is_array($response) || ($response['code'] ?? null) !== 200) {
            return null;
        }

        $data = $response['data'] ?? [];
        $list = is_array($data) ? ($data['list'] ?? []) : [];
        if (!is_array($list)) {
            return null;
        }

        foreach ($list as $item) {
            if (is_array($item) && (int)($item['id'] ?? 0) === $itemId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     */
    private function findRuleByType(array $rules, string $type): ?array
    {
        foreach ($rules as $rule) {
            if (($rule['type'] ?? null) === $type) {
                return $rule;
            }
        }

        return null;
    }

    private function loginAndGetToken(): ?string
    {
        $username = getenv('E2E_ADMIN_USERNAME') ?: 'admin';
        $password = getenv('E2E_ADMIN_PASSWORD') ?: '123123';

        $response = $this->requestJson(
            'POST',
            $this->getBaseUrl() . '/admin/api/auth/admin/login',
            [
                'username' => $username,
                'password' => $password,
            ]
        );

        if (!is_array($response) || ($response['code'] ?? null) !== 200) {
            return null;
        }

        $data = $response['data'] ?? [];
        if (!is_array($data)) {
            return null;
        }

        $token = $data['access_token'] ?? null;
        return is_string($token) && $token !== '' ? $token : null;
    }

    private function getBaseUrl(): string
    {
        $baseUrl = getenv('BACKEND_API_BASE_URL');

        if (!is_string($baseUrl) || trim($baseUrl) === '') {
            return 'http://127.0.0.1:8080';
        }

        return rtrim($baseUrl, '/');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestJson(string $method, string $url, array $payload = [], array $headers = []): ?array
    {
        $finalUrl = $url;
        $method = strtoupper($method);

        $headerLines = [
            'Accept: application/json',
        ];

        if ($method === 'GET' && !empty($payload)) {
            $query = http_build_query($payload);
            $finalUrl .= (str_contains($url, '?') ? '&' : '?') . $query;
        }

        $content = '';
        if ($method !== 'GET') {
            $headerLines[] = 'Content-Type: application/json';
            $content = (string)json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        foreach ($headers as $header) {
            $headerLines[] = $header;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines) . "\r\n",
                'content' => $content,
                'ignore_errors' => true,
                'timeout' => 6,
            ],
        ]);

        $raw = @file_get_contents($finalUrl, false, $context);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->fail('接口响应不是合法 JSON。');
        }

        return $decoded;
    }
}
