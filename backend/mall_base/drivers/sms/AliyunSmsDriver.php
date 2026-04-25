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

        try {
            // TODO: 接入阿里云 SDK
            // $client = new Dysmsapi(...);
            // $request = new SendSmsRequest([
            //     'phoneNumbers'  => $phone,
            //     'signName'      => $this->signName,
            //     'templateCode'  => $templateCode,
            //     'templateParam' => json_encode(array_merge(['code' => $code], $extra)),
            // ]);
            // $response = $client->sendSms($request);
            // if ($response->body->code !== 'OK') {
            //     $this->setError($response->body->message ?? '短信发送失败');
            //     return false;
            // }

            $this->log("发送短信验证码: {$phone}, scene: {$scene}, template: {$templateCode}");
            return true;

        } catch (\Exception $e) {
            $this->setError('发送失败: ' . $e->getMessage());
            return false;
        }
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

        try {
            $this->log("发送短信通知: {$phone}, params: " . json_encode($params));
            return true;
        } catch (\Exception $e) {
            $this->setError('发送失败: ' . $e->getMessage());
            return false;
        }
    }
}
