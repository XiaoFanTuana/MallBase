<?php

declare(strict_types=1);

namespace app\model\sms;

use mall_base\base\BaseModel;

/**
 * 短信场景模板绑定模型
 *
 * 通过 scene_code 反查得到 provider/template/sign,供 SmsService 发送时使用。
 */
class SmsSceneBinding extends BaseModel
{
    protected $name = 'sms_scene_binding';

    protected $autoWriteTimestamp = true;

    public function provider()
    {
        return $this->belongsTo(SmsProvider::class, 'provider_id', 'id');
    }

    public function template()
    {
        return $this->belongsTo(SmsTemplate::class, 'template_id', 'id');
    }

    public function sign()
    {
        return $this->belongsTo(SmsSign::class, 'sign_id', 'id');
    }
}
