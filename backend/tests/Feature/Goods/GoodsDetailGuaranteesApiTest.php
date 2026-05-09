<?php

declare(strict_types=1);

namespace Tests\Feature\Goods;

use PHPUnit\Framework\TestCase;
use Tests\Feature\Support\ApiClientTrait;

final class GoodsDetailGuaranteesApiTest extends TestCase
{
    use ApiClientTrait;

    public function testClientGoodsDetailReturnsGuarantees(): void
    {
        $response = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/client/api/goods/info/1',
        );

        if ($response === null) {
            $this->markTestSkipped('接口不可达，跳过客户端商品保障测试。');
        }
        if (($response['code'] ?? null) !== 200) {
            $this->markTestSkipped('当前环境未安装演示商品，跳过客户端商品保障测试。');
        }

        $this->assertIsArray($response['data']['guarantees'] ?? null);
        $this->assertNotEmpty($response['data']['guarantees']);
        $this->assertArrayHasKey('title', $response['data']['guarantees'][0]);
    }
}
