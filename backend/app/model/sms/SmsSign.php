<?php

declare(strict_types=1);

namespace app\model\sms;

use mall_base\base\BaseModel;

/**
 * 短信签名模型
 */
class SmsSign extends BaseModel
{
    protected $name = 'sms_sign';

    protected $autoWriteTimestamp = true;

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
     * 取该服务商下用于关联模板的签名名称
     *
     * 阿里云新接口 CreateSmsTemplate/UpdateSmsTemplate 强制要求 RelatedSignName,
     * 优先返回审核通过的签名,其次任意签名;无签名时返回空串,由调用方报错提示。
     */
    public static function resolveRelatedName(int $providerId): string
    {
        $passed = self::where('provider_id', $providerId)
            ->where('audit_status', self::AUDIT_PASSED)
            ->order('id', 'desc')
            ->value('sign_name');
        if ($passed !== null && $passed !== '') {
            return (string) $passed;
        }
        $any = self::where('provider_id', $providerId)
            ->order('id', 'desc')
            ->value('sign_name');
        return $any !== null ? (string) $any : '';
    }
}
