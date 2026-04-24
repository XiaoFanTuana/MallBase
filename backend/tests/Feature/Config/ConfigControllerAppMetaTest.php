<?php

declare(strict_types=1);

namespace Tests\Feature\Config;

use PHPUnit\Framework\TestCase;
use Tests\Feature\Support\ApiClientTrait;

/**
 * Admin ConfigController::appMeta 公开接口契约测试。
 *
 * 契约：
 * - GET /admin/api/config/appMeta 无需 JWT
 * - 响应 code=200
 * - data 包含 SystemBasic + SystemCopyright 的所有关键字段
 * - 图片字段返回 full_url（绝对 URL 或以 / 开头的站内路径）
 * - copyright_date 的 {year} 占位已替换为当前 4 位年份
 */
final class ConfigControllerAppMetaTest extends TestCase
{
    use ApiClientTrait;

    public function testAppMetaIsPublicWithoutAuth(): void
    {
        // **不带 Authorization 头**请求
        $response = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/admin/api/config/appMeta'
        );

        if ($response === null) {
            $this->markTestSkipped('后端接口不可达。');
        }

        $this->assertIsArray($response);
        // 必须通过（未登录也能拿到 200），而不是 401 / 403
        if (($response['code'] ?? null) !== 200) {
            $this->fail(sprintf(
                'appMeta 应为公开路由，未登录时预期 code=200，实际：%s',
                var_export($response['code'] ?? null, true)
            ));
        }

        $data = $response['data'] ?? [];
        $this->assertIsArray($data);

        // SystemBasic 关键字段
        foreach (
            ['site_name', 'admin_logo', 'admin_favicon', 'admin_login_title', 'admin_login_welcome']
            as $key
        ) {
            $this->assertArrayHasKey($key, $data, "appMeta 缺少 {$key}");
        }

        // SystemCopyright 关键字段
        foreach (
            ['copyright_enabled', 'copyright_company', 'copyright_date']
            as $key
        ) {
            $this->assertArrayHasKey($key, $data, "appMeta 缺少版权字段 {$key}");
        }
    }

    public function testAdminLogoIsFullUrlOrAbsolutePath(): void
    {
        $response = $this->requestJson('GET', $this->getBaseUrl() . '/admin/api/config/appMeta');
        if ($response === null) {
            $this->markTestSkipped('后端接口不可达。');
        }
        if (($response['code'] ?? null) !== 200) {
            $this->markTestSkipped('appMeta 未返回 200。');
        }

        $data = $response['data'] ?? [];
        $logo = $data['admin_logo'] ?? null;
        if (!is_string($logo) || $logo === '') {
            $this->markTestSkipped('admin_logo 未配置或为空。');
        }

        $this->assertMatchesRegularExpression(
            '#^(https?://.+|/.+)$#',
            $logo,
            'admin_logo 必须是 http(s):// 绝对 URL 或以 / 开头的站内路径'
        );
    }

    public function testCopyrightYearPlaceholderReplaced(): void
    {
        $response = $this->requestJson('GET', $this->getBaseUrl() . '/admin/api/config/appMeta');
        if ($response === null) {
            $this->markTestSkipped('后端接口不可达。');
        }
        if (($response['code'] ?? null) !== 200) {
            $this->markTestSkipped('appMeta 未返回 200。');
        }

        $data = $response['data'] ?? [];
        $date = $data['copyright_date'] ?? '';
        if (!is_string($date) || $date === '') {
            $this->markTestSkipped('copyright_date 未配置。');
        }

        $this->assertStringNotContainsString('{year}', $date, '{year} 占位应已替换');
        // 至少包含当前年份（默认模板是 "© {year}"）
        $currentYear = (string) date('Y');
        $this->assertStringContainsString(
            $currentYear,
            $date,
            sprintf('copyright_date 应含当前年份 %s，实际：%s', $currentYear, $date)
        );
    }
}
