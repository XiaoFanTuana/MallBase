<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use app\common\enum\PayScene;
use PHPUnit\Framework\TestCase;

/**
 * @covers \app\common\enum\PayScene
 */
final class PaySceneTest extends TestCase
{
    public function testConstantsAreStableAcrossReleases(): void
    {
        // 这三个值会写到 mb_order.pay_scene / mb_payment_log.scene，
        // 一旦调整就会破坏存量数据，故用断言锁死
        $this->assertSame(1, PayScene::MINI);
        $this->assertSame(2, PayScene::OFFI);
        $this->assertSame(3, PayScene::H5);
    }

    public function testTextOfReturnsHumanReadableLabel(): void
    {
        $this->assertSame('小程序', PayScene::textOf(PayScene::MINI));
        $this->assertSame('公众号', PayScene::textOf(PayScene::OFFI));
        $this->assertSame('H5', PayScene::textOf(PayScene::H5));
        $this->assertSame('未知', PayScene::textOf(0));
        $this->assertSame('未知', PayScene::textOf(99));
    }

    public function testIsValidAcceptsOnlyDefinedValues(): void
    {
        $this->assertTrue(PayScene::isValid(PayScene::MINI));
        $this->assertTrue(PayScene::isValid(PayScene::OFFI));
        $this->assertTrue(PayScene::isValid(PayScene::H5));
        $this->assertFalse(PayScene::isValid(0));
        $this->assertFalse(PayScene::isValid(9));
    }

    /**
     * @dataProvider provideCodeMappings
     */
    public function testFromCodeMapsClientStringToInt(string $code, ?int $expected): void
    {
        $this->assertSame($expected, PayScene::fromCode($code));
    }

    /**
     * @return iterable<array{string, ?int}>
     */
    public static function provideCodeMappings(): iterable
    {
        yield 'mini' => ['mini', PayScene::MINI];
        yield 'offi' => ['offi', PayScene::OFFI];
        yield 'h5'   => ['h5', PayScene::H5];
        yield 'unknown' => ['app', null];
        yield 'empty'   => ['', null];
        // 大小写敏感，必须严格小写
        yield 'uppercase' => ['MINI', null];
    }
}
