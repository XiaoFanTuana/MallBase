<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use PHPUnit\Framework\TestCase;
use Tests\Feature\Support\ApiClientTrait;

final class SearchHotApiTest extends TestCase
{
    use ApiClientTrait;

    public function testSearchLogAndHotRoutesReturnExpectedShape(): void
    {
        $keyword = '降噪耳机';

        $logResponse = $this->requestJson(
            'POST',
            $this->getBaseUrl() . '/client/api/search/log',
            [
                'keyword' => $keyword,
                'platform' => 'h5',
            ],
        );

        if ($logResponse === null) {
            $this->markTestSkipped('接口不可达，跳过客户端搜索接口测试。');
        }
        if (
            ($logResponse['code'] ?? null) === 400
            && str_contains((string) ($logResponse['message'] ?? ''), 'mb_search_log')
        ) {
            $this->markTestSkipped('当前测试数据库未创建 mb_search_log，跳过客户端搜索接口测试。');
        }

        $this->assertSame(200, $logResponse['code'] ?? null);
        $this->assertSame($keyword, $logResponse['data']['keyword'] ?? null);

        $hotResponse = $this->requestJson(
            'GET',
            $this->getBaseUrl() . '/client/api/search/hot',
            ['limit' => 5],
        );

        $this->assertSame(200, $hotResponse['code'] ?? null);
        $this->assertIsArray($hotResponse['data']['list'] ?? null);
        if (!empty($hotResponse['data']['list'])) {
            $this->assertArrayHasKey('keyword', $hotResponse['data']['list'][0]);
            $this->assertArrayHasKey('search_count', $hotResponse['data']['list'][0]);
        }
    }
}
