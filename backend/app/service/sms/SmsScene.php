<?php

declare(strict_types=1);

namespace app\service\sms;

/**
 * 短信验证码场景
 *
 * 区分不同业务场景，便于:
 *  - 模板 ID 映射(阿里云每个场景一个模板)
 *  - 频控按场景独立计算
 *  - 验证码 Redis key 按场景分桶
 */
class SmsScene
{
    /** 登录验证码 */
    public const LOGIN = 'login';

    /** 注册验证码 */
    public const REGISTER = 'register';

    /** 找回密码 */
    public const RESET_PASSWORD = 'reset_password';

    /** 绑定/换绑手机号 */
    public const BIND_MOBILE = 'bind_mobile';

    /** 公众号 OAuth 后强制绑定手机号 */
    public const WECHAT_OFFICIAL_BIND = 'wechat_official_bind';

    private const TEXTS = [
        self::LOGIN                => '登录验证码',
        self::REGISTER             => '注册验证码',
        self::RESET_PASSWORD       => '找回密码',
        self::BIND_MOBILE          => '绑定手机号',
        self::WECHAT_OFFICIAL_BIND => '公众号绑定手机号',
    ];

    public static function isValid(string $scene): bool
    {
        return array_key_exists($scene, self::TEXTS);
    }

    public static function textOf(string $scene): string
    {
        return self::TEXTS[$scene] ?? '未知场景';
    }

    /**
     * @return array<int, string>
     */
    public static function allValues(): array
    {
        return array_keys(self::TEXTS);
    }

    /**
     * 所有场景能向短信模板提供的参数白名单(占位符名称)
     *
     *  - code: 验证码本体(PNVS 注入 ##code## 由平台生成;企业版注入本地生成的 6 位码)
     *  - min:  验证码有效期分钟数(取自 SmsService::codeTtl/60)
     *
     * 用于:
     *  - SmsSceneService::bind() 校验模板占位符是否被场景覆盖
     *  - SmsService::resolveDriverForScene() 构造 templateParam
     *
     * 未来扩展 app_name / amount 等占位符,需在此追加 + 在 SmsService 注入逻辑里增加分支。
     *
     * @return array<int, string>
     */
    public static function availableParamNames(): array
    {
        return ['code', 'min'];
    }
}
