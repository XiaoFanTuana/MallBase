<?php

declare(strict_types=1);

namespace mall_base\drivers\sms;

/**
 * 阿里云短信驱动
 *
 * 接入时需要:
 *  1. composer require alibabacloud/dysmsapi-20170525
 *  2. 在 config/sms.php 的 aliyun 节补充 access_key_id / access_key_secret / sign_name / templates
 *  3. 实现 sendCode() 中的 SDK 调用
 */
class AliyunSmsDriver extends BaseSmsDriver
{
    protected string $signName = '';

    /** @var array<string, string> scene => template_code */
    protected array $templates = [];

    protected function init(): void
    {
        $this->signName = $this->getConfig('sign_name', '');
        $this->templates = (array) $this->getConfig('templates', []);
    }

    public function sendCode(string $phone, string $scene, string $code, array $extra = []): bool
    {
        if (!$this->validatePhone($phone)) {
            $this->setError('手机号格式不正确');
            return false;
        }

        $templateCode = $this->templates[$scene] ?? '';
        if ($templateCode === '') {
            $this->setError("场景 [{$scene}] 未配置阿里云短信模板");
            return false;
        }

        // TODO: 接入阿里云 SDK (composer require alibabacloud/dysmsapi-20170525)
        // 接入前使用 mock 驱动,切到 aliyun 必须先完成下方 SDK 调用
        $this->setError('阿里云短信 SDK 尚未接入,请先使用 mock 驱动或完成 SDK 集成');
        return false;
    }

    public function send(string $phone, string $code): bool
    {
        return $this->sendCode($phone, 'login', $code);
    }

    public function sendNotice(string $phone, array $params): bool
    {
        if (!$this->validatePhone($phone)) {
            $this->setError('手机号格式不正确');
            return false;
        }

        $this->setError('阿里云短信 SDK 尚未接入,请先使用 mock 驱动或完成 SDK 集成');
        return false;
    }
}
