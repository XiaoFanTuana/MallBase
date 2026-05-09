<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use PHPUnit\Framework\TestCase;

final class DemoAssetReferenceTest extends TestCase
{
    public function testDemoSqlReferencesExistingStaticDemoImages(): void
    {
        $root = dirname(__DIR__, 4);
        $sqlPath = $root . '/deploy/install/data/demo/02_demo_goods.sql';
        $sql = file_get_contents($sqlPath);

        $this->assertIsString($sql);
        preg_match_all("#/static/demo/[^'\"\\\\\]]+#", $sql, $matches);

        $missing = [];
        foreach (array_unique($matches[0] ?? []) as $path) {
            if (!is_file($root . '/backend/public' . $path)) {
                $missing[] = $path;
            }
        }

        $this->assertSame([], $missing, '演示 SQL 引用了不存在的静态图片');
    }

    public function testDemoHomeBannersAreValidJsonArray(): void
    {
        $root = dirname(__DIR__, 4);
        $sql = file_get_contents($root . '/deploy/install/data/demo/02_demo_goods.sql');

        $this->assertIsString($sql);
        $matched = preg_match(
            "/client_home_banners';\\s*\\nUPDATE `mb_setting` SET `value` = '([^']+)'/m",
            $sql,
            $matches,
        );
        if ($matched !== 1) {
            $matched = preg_match("/SET `value` = '([^']+)'\\s*\\nWHERE `code` = 'client_home_banners'/m", $sql, $matches);
        }

        $this->assertSame(1, $matched);
        $banners = json_decode($matches[1], true);
        $this->assertIsArray($banners);
        $this->assertNotEmpty($banners);
    }
}
