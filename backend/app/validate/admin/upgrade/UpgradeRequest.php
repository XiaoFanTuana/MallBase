<?php

declare(strict_types=1);

namespace app\validate\admin\upgrade;

use think\Validate;

/** Documents the bounded browser-facing upgrade input contract. */
final class UpgradeRequest extends Validate
{
    protected $rule = [
        'target_version|目标版本' => 'require|regex:/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/',
        'expected_revision|预期版本' => 'require|integer|egt:0',
        'request_id|请求标识' => 'require|regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
        'confirmation_nonce|确认凭据' => 'require|regex:/^[A-Za-z0-9_-]{43}$/',
        'action|操作' => 'require|in:cancel,resume,rollback',
    ];

    protected $scene = [
        'job' => ['target_version', 'expected_revision'],
        'confirm' => ['request_id', 'confirmation_nonce'],
        'control' => ['expected_revision', 'action'],
    ];
}
