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
}
