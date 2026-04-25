<?php

declare(strict_types=1);

namespace mall_base\drivers\sms;

/**
 * Mock 短信驱动
 *
 * 用于本地开发、联调和单元测试,不真发短信,只输出日志。
 * 验证码的存活由 SmsService 独立管理(Redis TTL),本类不参与。
 */
class MockSmsDriver extends BaseSmsDriver
{
    protected function init(): void
    {
    }

    public function sendCode(string $phone, string $scene, string $code, array $extra = []): bool
    {
        $payload = [
            'mobile'  => $this->maskMobile($phone),
            'scene'   => $scene,
            'code'    => $code,
            'extra'   => $extra,
            'channel' => 'mock',
        ];

        try {
            \mall_base\log\Logger::instance()->info('[SMS-Mock] 模拟发送验证码', $payload);
        } catch (\Throwable) {
            error_log('[SMS-Mock] ' . json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        return true;
    }

    public function send(string $phone, string $code): bool
    {
        return $this->sendCode($phone, 'default', $code);
    }

    public function sendNotice(string $phone, array $params): bool
    {
        try {
            $this->log("发送短信通知: {$phone}, params: " . json_encode($params, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable) {
            error_log("[SMS-Mock] sendNotice: {$phone} " . json_encode($params, JSON_UNESCAPED_UNICODE));
        }
        return true;
    }

    private function maskMobile(string $mobile): string
    {
        if (strlen($mobile) < 11) {
            return $mobile;
        }
        return substr($mobile, 0, 3) . '****' . substr($mobile, -4);
    }
}
