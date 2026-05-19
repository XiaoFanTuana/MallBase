<?php

declare(strict_types=1);

namespace app\validate\admin\sms;

use think\Validate;

class SmsSceneValidate extends Validate
{
    protected $rule = [
        'scene_code|场景编码' => 'require|max:40',
        'provider_id|服务商' => 'require|integer|gt:0',
        'template_id|模板' => 'integer',
        'sign_id|签名' => 'integer',
        'status|状态' => 'integer|in:0,1',
    ];

    protected $scene = [
        'bind' => ['scene_code', 'provider_id', 'template_id', 'sign_id', 'status'],
    ];
}
