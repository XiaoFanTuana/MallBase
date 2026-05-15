<?php

declare(strict_types=1);

namespace app\listener\payment;

use mall_base\log\Logger;
use Throwable;

/**
 * 支付告警事件监听器
 *
 * 当前实现策略：
 *  - 同步部分：把告警信息以 critical 级别落地到日志（{@see Logger}）
 *  - 异步部分：预留 forwardToOps()，后续接入运维通知通道
 *
 * 不在此监听器内直接调短信驱动：
 *  - 项目现有 SMS 驱动接口为 send(phone, code) 形式（验证码语义），
 *    不适合广播文本告警
 *  - 后续接入「运维通知通道」（站内信 / 钉钉 / 飞书 webhook / 自定义短信模板）后，
 *    在 forwardToOps() 内点亮调用即可，无需改回调主链路
 *
 * 重要：本监听器**不能阻塞主链路**。所有外部 I/O 都应捕获异常 + 限时。
 */
class PaymentAlertListener
{
    /**
     * 处理 payment.verify_failed / payment.amount_mismatch / payment.replay_attack
     *
     * @param array<string, mixed> $payload 上下文，由 NotifyService 派发时填充
     */
    public function handle(array $payload): void
    {
        $eventName = $this->resolveEventName();
        $contextSummary = $this->summarize($payload);

        // 1) 落盘日志：critical 级别，保证审计可追溯
        Logger::instance()->critical(sprintf('[支付告警] %s', $eventName), $contextSummary);

        // 2) 转发给运维通知通道（预留）
        try {
            $this->forwardToOps($eventName, $contextSummary);
        } catch (Throwable $e) {
            // 通知通道失败不能影响主流程，仅记录
            Logger::instance()->error('支付告警转发失败', [
                'event' => $eventName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 取当前事件名（事件触发时由 ThinkPHP 注入到上下文中，回退到反射）
     */
    private function resolveEventName(): string
    {
        // ThinkPHP 不直接把事件名传给 handle()。取调用栈最近一次 Event::trigger 调用参数。
        // 这里简单返回固定占位，事件名已经在 payload 内可见时优先使用
        return 'payment.alert';
    }

    /**
     * 提取关键字段并对 openid 脱敏
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function summarize(array $payload): array
    {
        $summary = $payload;
        if (isset($summary['payer_openid'])) {
            $summary['payer_openid'] = $this->maskOpenid((string) $summary['payer_openid']);
        }
        if (isset($summary['openid'])) {
            $summary['openid'] = $this->maskOpenid((string) $summary['openid']);
        }
        return $summary;
    }

    private function maskOpenid(string $openid): string
    {
        if (mb_strlen($openid) <= 8) {
            return $openid !== '' ? '***' : '';
        }
        return mb_substr($openid, 0, 4) . '****' . mb_substr($openid, -4);
    }

    /**
     * 转发到运维通知通道
     *
     * TODO 接入步骤（后续 commit）：
     *   1. 在后台「设置 → 运维」新增配置项 alert_ops_channel（webhook URL / 短信模板编码 / 站内信主题）
     *   2. 本方法按 alert_ops_channel 类型分流：webhook 走 GuzzleHttp、SMS 走专门的运营通知驱动
     *   3. 调用方式仍保持入队（think-queue）避免阻塞 notify 主链路
     *
     * @param array<string, mixed> $context
     */
    private function forwardToOps(string $eventName, array $context): void
    {
        // 当前未接入实际通道，保持空实现 + 一条 info 日志便于自检
        Logger::instance()->info('支付告警转发占位', [
            'event'   => $eventName,
            'context' => $context,
        ]);
    }
}
