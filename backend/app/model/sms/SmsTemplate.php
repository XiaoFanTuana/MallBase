<?php

declare(strict_types=1);

namespace app\model\sms;

use mall_base\base\BaseModel;

/**
 * 短信模板模型
 */
class SmsTemplate extends BaseModel
{
    protected $name = 'sms_template';

    protected $autoWriteTimestamp = true;

    /** 模板类型:验证码 */
    public const TYPE_VERIFICATION = 0;

    /** 模板类型:通知 */
    public const TYPE_NOTICE = 1;

    /** 模板类型:推广 */
    public const TYPE_PROMOTION = 2;

    /** 模板类型:国际/港澳台 */
    public const TYPE_INTERNATIONAL = 3;

    /** 待审核 */
    public const AUDIT_PENDING = 'pending';

    /** 审核通过 */
    public const AUDIT_PASSED = 'passed';

    /** 审核驳回 */
    public const AUDIT_REJECTED = 'rejected';

    /** 仅本地(未提交远端) */
    public const AUDIT_LOCAL_ONLY = 'local_only';

    public function provider()
    {
        return $this->belongsTo(SmsProvider::class, 'provider_id', 'id');
    }

    /**
     * 从模板内容中抽取 ${xxx} 形式的占位符名称(去重)
     *
     * 用于场景绑定时校验模板需要的参数是否能被场景白名单覆盖,
     * 以及发送时按占位符构造 templateParam。
     *
     * @return array<int, string>
     */
    public static function extractPlaceholders(string $content): array
    {
        if ($content === '') {
            return [];
        }
        preg_match_all('/\$\{(\w+)\}/', $content, $matches);
        return array_values(array_unique($matches[1] ?? []));
    }
}
