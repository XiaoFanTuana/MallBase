<?php

declare(strict_types=1);

namespace app\service\order;

use app\model\order\PaymentLog;
use app\service\client\payment\WechatPayClient;
use app\service\client\payment\WechatPayFactory;
use mall_base\exception\BusinessException;

/**
 * 微信预支付单关闭服务
 *
 * 只做微信侧 close 调用；本地 payment_log 状态由调用方事务内更新。
 */
class WechatPrepayCloseService
{
    public function __construct(
        private readonly WechatPayFactory $factory,
        private readonly WechatPayClient $client,
    ) {
    }

    /**
     * @return array<int, PaymentLog>
     */
    public function activePrepayLogs(int $orderId, ?int $excludeScene = null): array
    {
        $query = PaymentLog::where('order_id', $orderId)
            ->where('event_type', PaymentLog::EVENT_PREPAY)
            ->where('expire_at', '>', date('Y-m-d H:i:s'))
            ->order('id', 'desc');

        if ($excludeScene !== null) {
            $query->where('scene', '<>', $excludeScene);
        }

        return $query->select()->all();
    }

    /**
     * @param array<int, PaymentLog> $logs
     */
    public function closeLogs(array $logs): void
    {
        if ($logs === []) {
            return;
        }

        $app = $this->factory->build();
        $mchId = trim((string) getSystemSetting('pay_wechat_mchid', ''));
        if ($mchId === '') {
            throw new BusinessException('微信商户号未配置，无法关闭预支付单');
        }
        foreach ($logs as $log) {
            $outTradeNo = trim((string) ($log->out_trade_no ?? ''));
            if ($outTradeNo === '') {
                continue;
            }
            $this->client->closeByOutTradeNo($app, $mchId, $outTradeNo);
        }
    }

    /**
     * @param array<int, PaymentLog> $logs
     * @return array<int, int>
     */
    public function idsOf(array $logs): array
    {
        return array_values(array_filter(array_map(
            static fn(PaymentLog $log): int => (int) ($log->id ?? 0),
            $logs
        )));
    }
}
