<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
use app\command\Docs;
use app\command\SyncPermissions;

return [
    // 指令定义
    'commands' => [
        'docs' => Docs::class,
        'sync:permissions' => SyncPermissions::class,
    ],
];
