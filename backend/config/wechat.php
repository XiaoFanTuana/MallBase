<?php

// 微信配置
return [
    // 微信小程序配置
    'mini_program' => [
        'app_id' => env('WECHAT_MINI_PROGRAM_APP_ID', ''),
        'app_secret' => env('WECHAT_MINI_PROGRAM_APP_SECRET', ''),
    ],

    // 微信开放平台配置（用于获取 unionid）
    'open_platform' => [
        'app_id' => env('WECHAT_OPEN_PLATFORM_APP_ID', ''),
        'app_secret' => env('WECHAT_OPEN_PLATFORM_APP_SECRET', ''),
    ],

    // 微信公众号配置（可选）
    'offi_account' => [
        'app_id' => env('WECHAT_OFFI_ACCOUNT_APP_ID', ''),
        'app_secret' => env('WECHAT_OFFI_ACCOUNT_APP_SECRET', ''),
    ],
];
