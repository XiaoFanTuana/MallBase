<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use app\service\client\payment\WechatPayClient;
use app\service\order\WechatPrepayCloseService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PaymentCloseConsistencyContractTest extends TestCase
{
    public function testWechatCloseOrderClientIsAvailable(): void
    {
        $ref = new ReflectionClass(WechatPayClient::class);
        $this->assertTrue($ref->hasMethod('closeByOutTradeNo'));

        $source = file_get_contents(__DIR__ . '/../../../app/service/client/payment/WechatPayClient.php');
        $this->assertIsString($source);
        $this->assertStringContainsString('/v3/pay/transactions/out-trade-no/', $source);
        $this->assertStringContainsString('/close', $source);
    }

    public function testPrepayCloseServiceAndCallSitesExist(): void
    {
        $this->assertTrue(class_exists(WechatPrepayCloseService::class));

        $adminSource = file_get_contents(__DIR__ . '/../../../app/service/admin/order/OrderAdminService.php');
        $prepaySource = file_get_contents(__DIR__ . '/../../../app/service/client/payment/PrepayService.php');
        $notifySource = file_get_contents(__DIR__ . '/../../../app/service/client/payment/NotifyService.php');

        $this->assertIsString($adminSource);
        $this->assertIsString($prepaySource);
        $this->assertIsString($notifySource);
        $this->assertStringContainsString('closeLogs($prepayLogs)', $adminSource);
        $this->assertStringContainsString('PaymentLog::EVENT_CLOSED', $adminSource);
        $this->assertStringContainsString('WechatPrepayCloseService', $prepaySource);
        $this->assertStringContainsString('amount_cents', $prepaySource);
        $this->assertStringContainsString('PaymentLog::EVENT_SUPERSEDED', $prepaySource);
        $this->assertStringContainsString('微信支付回调命中非活跃预支付流水', $notifySource);
        $this->assertStringContainsString("respond(500, 'FAIL'", $notifySource);
    }
}
