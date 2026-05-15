<?php

declare(strict_types=1);

namespace app\service\sms;

use app\model\sms\SmsProvider;
use app\model\sms\SmsSceneBinding;
use app\model\sms\SmsSign;
use app\model\sms\SmsTemplate;
use mall_base\drivers\DriverManager;
use mall_base\drivers\sms\BaseSmsDriver;
use mall_base\exception\SmsException;

/**
 * 短信验证码服务(业务入口)
 *
 * 职责:
 *  - 生成 6 位数字验证码
 *  - 走频控
 *  - 调驱动发送
 *  - 验证码存 SmsCache(独立于驱动,方便 mock 模式联调)
 *  - 提供 verifyCode(mobile, scene, code) 业务校验接口
 *
 * 驱动解析:
 *  - 构造期注入 driver -> 直接使用(用于单元测试 / mock 模式)
 *  - 构造期 driver=null -> 按 SmsSceneBinding 动态解析服务商、模板、签名
 *
 * 兼容性硬约束:
 *  - sendCode 方法签名保持不变,uniapp 调用方零修改
 */
class SmsService
{
    private const CODE_KEY_PREFIX = 'sms:code:';

    public function __construct(
        private readonly ?BaseSmsDriver $driver,
        private readonly SmsRateLimiter $rateLimiter,
        private readonly SmsCache $cache,
        private readonly int $codeTtl = 300,
    ) {
    }

    /**
     * 发送验证码
     *
     * @throws SmsException 频控命中或渠道发送失败
     */
    public function sendCode(string $mobile, string $scene, string $ip = '', array $extra = []): void
    {
        $this->assertScene($scene);
        $this->assertMobile($mobile);

        $this->rateLimiter->assertCanSend($mobile, $ip);

        $code = $this->generateCode();

        [$driver, $sendExtra] = $this->resolveDriverForScene($scene, $code, $extra);

        if (!$driver->sendCode($mobile, $scene, $code, $sendExtra)) {
            throw new SmsException($driver->getError() ?: '短信发送失败');
        }

        $this->rateLimiter->record($mobile, $ip);
        $this->cache->set($this->codeKey($mobile, $scene), $code, $this->codeTtl);
    }

    /**
     * 校验验证码
     *
     * 校验通过后立即删除缓存中的验证码,防止重放
     *
     * @throws SmsException 验证码不存在/过期/不匹配
     */
    public function verifyCode(string $mobile, string $scene, string $code): void
    {
        $this->assertScene($scene);
        $this->assertMobile($mobile);

        $key = $this->codeKey($mobile, $scene);
        $stored = $this->cache->get($key);
        if (empty($stored)) {
            throw new SmsException('验证码已过期,请重新获取');
        }
        if ((string) $stored !== trim($code)) {
            throw new SmsException('验证码错误');
        }

        $this->cache->delete($key);
    }

    /**
     * 仅 mock 驱动下使用:取出当前验证码用于自动化测试
     *
     * @internal 不要在生产代码中调用
     */
    public function peekCodeForTesting(string $mobile, string $scene): ?string
    {
        $value = $this->cache->get($this->codeKey($mobile, $scene));
        return $value === null ? null : (string) $value;
    }

    /**
     * 解析当前场景应使用的驱动与发送参数
     *
     * @return array{0: BaseSmsDriver, 1: array<string, mixed>}
     */
    private function resolveDriverForScene(string $scene, string $code, array $extra): array
    {
        // 显式注入的驱动优先(测试 / mock 模式)
        if ($this->driver !== null) {
            return [$this->driver, $extra];
        }

        $binding = SmsSceneBinding::where('scene_code', $scene)
            ->where('status', 1)
            ->find();
        if ($binding === null) {
            throw new SmsException("场景 [{$scene}] 尚未绑定短信模板,请在后台短信配置中完成绑定");
        }

        $template = SmsTemplate::find($binding->template_id);
        if ($template === null) {
            throw new SmsException("场景 [{$scene}] 绑定的模板不存在,请重新绑定");
        }
        if ($template->audit_status !== SmsTemplate::AUDIT_PASSED) {
            throw new SmsException("场景 [{$scene}] 绑定的模板尚未审核通过");
        }

        $sign = SmsSign::find($binding->sign_id);
        if ($sign === null) {
            throw new SmsException("场景 [{$scene}] 绑定的签名不存在,请重新绑定");
        }
        if ($sign->audit_status !== SmsSign::AUDIT_PASSED) {
            throw new SmsException("场景 [{$scene}] 绑定的签名尚未审核通过");
        }

        $provider = SmsProvider::find($binding->provider_id);
        if ($provider === null || (int) $provider->status !== 1) {
            throw new SmsException("场景 [{$scene}] 绑定的服务商不可用,请检查启用状态");
        }

        $driver = DriverManager::create('sms', (string) $provider->driver, [
            'access_key_id' => (string) $provider->access_key_id,
            'access_key_secret' => SmsSecret::decrypt((string) $provider->access_key_secret),
            'region' => (string) $provider->region,
        ]);

        $extra['sign_name'] = $sign->sign_name;
        $extra['template_code'] = $template->template_code;
        $extra['template_param'] = $extra['template_param'] ?? ['code' => $code];

        return [$driver, $extra];
    }

    private function codeKey(string $mobile, string $scene): string
    {
        return self::CODE_KEY_PREFIX . $scene . ':' . $mobile;
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function assertScene(string $scene): void
    {
        if (!SmsScene::isValid($scene)) {
            throw new SmsException('无效的短信场景');
        }
    }

    private function assertMobile(string $mobile): void
    {
        if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
            throw new SmsException('手机号格式不正确');
        }
    }
}
