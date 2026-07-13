<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use app\common\enum\OperatorType;
use app\common\enum\RefundOrderStatus;
use app\model\order\RefundOrder;
use app\service\order\RefundOrderStatusMachine;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;
use Throwable;

final class RefundOrderStatusMachineConcurrencyContractTest extends TestCase
{
    private ?App $app = null;

    public function testTransitRejectsStaleRefundAfterTerminalStateWasReached(): void
    {
        $this->requireRefundTable();

        Db::startTrans();
        try {
            $refundId = $this->insertRefund(RefundOrderStatus::PENDING);
            /** @var RefundOrder $staleRefund */
            $staleRefund = RefundOrder::where('id', $refundId)->find();

            Db::name('refund_order')->where('id', $refundId)->update([
                'status' => RefundOrderStatus::CLOSED,
                'canceled_at' => date('Y-m-d H:i:s'),
            ]);

            try {
                $this->machine()->transit(
                    $staleRefund,
                    RefundOrderStatus::REJECTED,
                    OperatorType::ADMIN,
                    1,
                    'stale reject',
                );
                self::fail('预期旧售后对象不能覆盖数据库终态');
            } catch (BusinessException $e) {
                self::assertStringContainsString('已关闭', $e->getMessage());
                self::assertStringContainsString('已拒绝', $e->getMessage());
            }

            self::assertSame(
                RefundOrderStatus::CLOSED,
                (int) Db::name('refund_order')->where('id', $refundId)->value('status')
            );
        } finally {
            Db::rollback();
        }
    }

    private function requireRefundTable(): void
    {
        try {
            $this->app = new App(dirname(__DIR__, 3));
            $this->app->initialize();
            $table = (string) Db::name('refund_order')->getTable();
            $rows = Db::query(sprintf(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' LIMIT 1",
                str_replace("'", "''", $table)
            ));
            if ($rows === []) {
                $this->markTestSkipped("测试数据库未创建 {$table}，跳过售后状态机并发契约测试。");
            }
        } catch (Throwable $e) {
            $this->markTestSkipped('测试数据库不可用，跳过售后状态机并发契约测试：' . $e->getMessage());
        }
    }

    private function machine(): RefundOrderStatusMachine
    {
        return $this->app?->make(RefundOrderStatusMachine::class)
            ?? app()->make(RefundOrderStatusMachine::class);
    }

    private function insertRefund(int $status): int
    {
        return (int) Db::name('refund_order')->insertGetId([
            'sn' => 'CRC' . date('ymdHis') . random_int(100000, 999999),
            'order_id' => random_int(600000000, 700000000),
            'order_item_id' => null,
            'user_id' => random_int(700000001, 800000000),
            'type' => RefundOrderStatus::TYPE_REFUND_ONLY,
            'receive_status' => RefundOrderStatus::RECEIVE_RECEIVED,
            'status' => $status,
            'quantity' => 1,
            'refund_amount' => '10.00',
            'reason' => 'TEST',
            'remark' => 'concurrency contract',
        ]);
    }
}
