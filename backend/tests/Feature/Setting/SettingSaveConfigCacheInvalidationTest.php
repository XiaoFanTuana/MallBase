<?php

declare(strict_types=1);

namespace Tests\Feature\Setting;

use app\service\cache\SettingCacheService;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Support\ApiClientTrait;
use think\App;
use think\facade\Cache;
use Throwable;

final class SettingSaveConfigCacheInvalidationTest extends TestCase
{
    use ApiClientTrait;

    public function testClearSettingValuesInvalidatesTaggedSingleValueCache(): void
    {
        if (gethostbyname('redis') === 'redis' && getenv('REDIS_HOST') === false) {
            $this->markTestSkipped('当前宿主机无法解析 redis 容器域名，跳过直接 Redis 缓存断言。');
        }

        $code = 'codex_clear_setting_value_tag_probe';
        $cacheKey = 'setting:value:' . $code;
        $cacheReady = false;

        try {
            $app = new App(dirname(__DIR__, 3));
            $app->initialize();
            $cacheReady = true;
            Cache::delete($cacheKey);

            /** @var SettingCacheService $cacheService */
            $cacheService = $app->make(SettingCacheService::class);
            $cachedValue = $cacheService->getSettingValue($code, static fn(): string => 'tagged-value');
            $hasCacheBeforeClear = Cache::has($cacheKey);
            $cacheService->clearSettingValues([$code]);
            $hasCacheAfterClear = Cache::has($cacheKey);
        } catch (Throwable $e) {
            $this->markTestSkipped('缓存服务不可用，跳过直接缓存断言：' . $e->getMessage());
        } finally {
            if ($cacheReady) {
                Cache::delete($cacheKey);
            }
        }

        $this->assertSame('tagged-value', $cachedValue);
        $this->assertTrue($hasCacheBeforeClear, '单值缓存预热后应写入 Redis。');
        $this->assertFalse($hasCacheAfterClear, 'clearSettingValues 应通过 setting:value tag 清除单值缓存。');
    }

    public function testSaveSystemBasicInvalidatesSingleValueCacheUsedByUploadUrl(): void
    {
        $token = $this->loginAndGetToken();
        if ($token === null) {
            $this->markTestSkipped('登录失败或接口不可达。');
        }

        $headers = ["Authorization: Bearer {$token}"];
        $systemBasic = $this->loadConfigValues('SystemBasic', $headers);
        $uploadLocal = $this->loadConfigValues('UploadLocal', $headers);

        if ($systemBasic === null || $uploadLocal === null) {
            $this->markTestSkipped('配置接口不可达或未返回 200。');
        }

        $originalSystemBasic = $systemBasic;

        if (($uploadLocal['local_base_url'] ?? '') !== '') {
            $this->markTestSkipped('local_base_url 非空时上传域名不回退 site_url，跳过该缓存失效用例。');
        }

        $oldUrl = 'https://cache-old.mallbase.test';
        $newUrl = 'https://cache-new.mallbase.test';

        try {
            $systemBasic['site_url'] = $oldUrl;
            $this->saveConfigValues('SystemBasic', $systemBasic, $headers);

            $oldLogo = $this->loadAdminLogoFromAppMeta();
            if ($oldLogo === null) {
                $this->markTestSkipped('appMeta 未返回可用于断言的 admin_logo。');
            }
            $this->assertStringStartsWith($oldUrl, $oldLogo);

            $systemBasic['site_url'] = $newUrl;
            $this->saveConfigValues('SystemBasic', $systemBasic, $headers);

            $newLogo = $this->loadAdminLogoFromAppMeta();
            if ($newLogo === null) {
                $this->markTestSkipped('appMeta 未返回可用于断言的 admin_logo。');
            }

            $this->assertStringStartsWith(
                $newUrl,
                $newLogo,
                'saveConfig 更新 site_url 后，依赖 getSystemSetting(site_url) 的上传 URL 应立即使用新值。'
            );
        } finally {
            $this->saveConfigValues('SystemBasic', $originalSystemBasic, $headers);
        }
    }

    /**
     * @param array<int, string> $headers
     * @return array<string, mixed>|null
     */
    private function loadConfigValues(string $groupCode, array $headers): ?array
    {
        $response = $this->requestJson(
            'GET',
            $this->getBaseUrl() . "/admin/api/setting/item/config/{$groupCode}",
            [],
            $headers
        );

        if (!is_array($response) || ($response['code'] ?? null) !== 200) {
            return null;
        }

        $data = $response['data'] ?? [];
        if (!is_array($data)) {
            return null;
        }

        return $this->collectConfigValues($data);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, string> $headers
     */
    private function saveConfigValues(string $groupCode, array $values, array $headers): void
    {
        $response = $this->requestJson(
            'POST',
            $this->getBaseUrl() . "/admin/api/setting/item/saveConfig/{$groupCode}",
            $values,
            $headers
        );

        $this->assertIsArray($response, "保存 {$groupCode} 配置接口不可达。");
        $this->assertSame(200, $response['code'] ?? null, "保存 {$groupCode} 配置失败。");
    }

    private function loadAdminLogoFromAppMeta(): ?string
    {
        $response = $this->requestJson('GET', $this->getBaseUrl() . '/admin/api/config/appMeta');
        if (!is_array($response) || ($response['code'] ?? null) !== 200) {
            return null;
        }

        $data = $response['data'] ?? [];
        if (!is_array($data)) {
            return null;
        }

        $logo = $data['admin_logo'] ?? null;
        return is_string($logo) && $logo !== '' ? $logo : null;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function collectConfigValues(array $config): array
    {
        $values = [];
        foreach (($config['settings'] ?? []) as $setting) {
            if (is_array($setting) && isset($setting['code'])) {
                $values[(string)$setting['code']] = $setting['value'] ?? '';
            }
        }

        foreach (($config['tabs'] ?? []) as $tab) {
            if (!is_array($tab)) {
                continue;
            }
            foreach (($tab['settings'] ?? []) as $setting) {
                if (is_array($setting) && isset($setting['code'])) {
                    $values[(string)$setting['code']] = $setting['value'] ?? '';
                }
            }
        }

        return $values;
    }
}
