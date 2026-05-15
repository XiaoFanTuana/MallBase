<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use app\service\client\payment\dto\PrepayContext;
use app\service\client\payment\dto\PrepayResult;
use PHPUnit\Framework\TestCase;

/**
 * 验证 prepay DTO 是 readonly：
 *  - 跨方法传递时不会被改写（PSR-12 immutable）
 *  - 一旦构造完成，赋值应抛 Error
 *
 * @covers \app\service\client\payment\dto\PrepayContext
 * @covers \app\service\client\payment\dto\PrepayResult
 */
final class PrepayDtoImmutabilityTest extends TestCase
{
    public function testPrepayContextIsImmutable(): void
    {
        $ctx = new PrepayContext(
            orderId: 101,
            orderSn: 'MB2611150001',
            outTradeNo: 'MB2611150001-A1B2C3',
            scene: 1,
            amountCents: 198,
            description: '订单 MB2611150001',
            payerOpenid: 'oTestOpenid12345',
            clientIp: '',
            notifyUrl: 'https://example.com/api/notify/wechat/pay',
            expireAt: '2026-05-15T18:00:00+08:00',
        );

        $this->assertSame(101, $ctx->orderId);
        $this->assertSame('MB2611150001-A1B2C3', $ctx->outTradeNo);
        $this->assertSame(198, $ctx->amountCents);

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line readonly
        $ctx->amountCents = 999;
    }

    public function testPrepayResultIsImmutable(): void
    {
        $result = new PrepayResult(
            outTradeNo: 'MB2611150001-A1B2C3',
            prepayId: 'wx2026051517300012345',
            mwebUrl: '',
            payload: ['paySign' => 'fake-sign'],
        );

        $this->assertSame('wx2026051517300012345', $result->prepayId);
        $this->assertSame(['paySign' => 'fake-sign'], $result->payload);

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line readonly
        $result->prepayId = 'tampered';
    }

    public function testPrepayResultPayloadIsArray(): void
    {
        $result = new PrepayResult(
            outTradeNo: 'X',
            prepayId: '',
            mwebUrl: 'https://wx.tenpay.com/cgi-bin/mmpayweb-bin/checkmweb?prepay_id=xxx',
            payload: ['mweb_url' => 'https://wx.tenpay.com/...'],
        );

        $this->assertSame('', $result->prepayId);
        $this->assertSame('mweb_url', array_key_first($result->payload));
    }
}
