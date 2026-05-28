<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Order;

use PHPUnit\Framework\TestCase;

final class UniappOrderFrontendContractTest extends TestCase
{
    public function testOrderListAndDetailUseExpireAtCountdownAndRefundGate(): void
    {
        $listSource = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages/order/index.vue');
        $detailSource = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages-sub/order/detail.vue');

        $this->assertIsString($listSource);
        $this->assertIsString($detailSource);

        foreach ([$listSource, $detailSource] as $source) {
            $this->assertStringContainsString('expire_at', $source);
            $this->assertStringContainsString('isPendingPayExpired', $source);
            $this->assertStringContainsString('can_refund', $source);
            $this->assertStringContainsString('订单已超过售后申请期限', $source);
        }
    }

    public function testPayResultTreatsBackendPaidStatusAsSuccess(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages-sub/order/pay-result.vue');
        $this->assertIsString($source);

        $this->assertStringContainsString('const ORDER_STATUS_PAID = 10', $source);
        $this->assertStringNotContainsString('const ORDER_STATUS_PAID = 2', $source);
    }

    public function testAdminDynamicFormSupportsOptionListWithoutJsonEditing(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../../../frontend/admin/apps/web-antd/src/views/settings/dynamic-form/index.vue');
        $this->assertIsString($source);

        $this->assertStringContainsString("item.type === 'option_list'", $source);
        $this->assertStringContainsString('getOptionListRows', $source);
        $this->assertStringContainsString('addOptionListRow', $source);
        $this->assertStringContainsString('removeOptionListRow', $source);
        $this->assertStringContainsString('新增选项', $source);
        $this->assertStringContainsString('CUSTOM_', $source);
    }
}
