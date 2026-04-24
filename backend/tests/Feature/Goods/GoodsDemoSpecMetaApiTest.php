<?php

declare(strict_types=1);

namespace Tests\Feature\Goods;

use PHPUnit\Framework\TestCase;
use Tests\Feature\Support\ApiClientTrait;

final class GoodsDemoSpecMetaApiTest extends TestCase
{
    use ApiClientTrait;

    public function testDemoGoodsListAndInfoUseCurrentSpecMetaShape(): void
    {
        $token = $this->loginAndGetToken();
        if ($token === null) {
            $this->markTestSkipped('登录失败或接口不可达，跳过 demo 商品规格结构测试。');
        }

        $headers = ["Authorization: Bearer {$token}"];

        $listRaw = $this->requestJsonRaw(
            'GET',
            $this->getBaseUrl() . '/admin/api/goods/list/list',
            ['page' => 1, 'limit' => 10],
            $headers
        );

        if ($listRaw === null) {
            $this->markTestSkipped('后端接口不可达，跳过 demo 商品规格结构测试。');
        }

        $listResponse = json_decode($listRaw, true);
        $this->assertIsArray($listResponse, 'goods/list/list 响应必须是合法 JSON。');

        if (($listResponse['code'] ?? null) !== 200) {
            $this->markTestSkipped('goods/list/list 未返回 200，可能当前环境未安装 demo 数据。');
        }

        $list = $listResponse['data']['list'] ?? [];
        $this->assertIsArray($list);

        $targetGoods = null;
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ((int) ($item['spec_type'] ?? 0) !== 2) {
                continue;
            }

            $targetGoods = $item;
            break;
        }

        if ($targetGoods === null) {
            $this->markTestSkipped('当前环境未找到多规格 demo 商品，跳过结构断言。');
        }

        $this->assertSpecMetaShape($targetGoods['spec_meta'] ?? null);

        $goodsId = (int) ($targetGoods['id'] ?? 0);
        $this->assertGreaterThan(0, $goodsId);

        $infoRaw = $this->requestJsonRaw(
            'GET',
            $this->getBaseUrl() . "/admin/api/goods/list/info/{$goodsId}",
            [],
            $headers
        );

        if ($infoRaw === null) {
            $this->markTestSkipped('商品详情接口不可达，跳过结构断言。');
        }

        $infoResponse = json_decode($infoRaw, true);
        $this->assertIsArray($infoResponse, 'goods/list/info 响应必须是合法 JSON。');
        $this->assertSame(200, $infoResponse['code'] ?? null, 'goods/list/info 应返回 200。');

        $info = $infoResponse['data'] ?? null;
        $this->assertIsArray($info);
        $this->assertSpecMetaShape($info['spec_meta'] ?? null);
    }

    private function assertSpecMetaShape(mixed $specMeta): void
    {
        $this->assertIsArray($specMeta);
        $this->assertNotEmpty($specMeta, '多规格商品的 spec_meta 不应为空。');

        $group = $specMeta[0] ?? null;
        $this->assertIsArray($group);
        $this->assertArrayHasKey('name', $group);
        $this->assertArrayHasKey('add_pic', $group);
        $this->assertArrayHasKey('values', $group);
        $this->assertArrayNotHasKey('spec_name', $group);
        $this->assertIsString($group['name']);
        $this->assertContains((int) $group['add_pic'], [0, 1]);
        $this->assertIsArray($group['values']);
        $this->assertNotEmpty($group['values'], '规格组选项不应为空。');

        $value = $group['values'][0] ?? null;
        $this->assertIsArray($value);
        $this->assertArrayHasKey('value', $value);
        $this->assertArrayHasKey('pic', $value);
        $this->assertArrayHasKey('pic_full_url', $value);
        $this->assertIsString($value['value']);
        $this->assertIsString($value['pic']);
        $this->assertIsString($value['pic_full_url']);
    }
}
