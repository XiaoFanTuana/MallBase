<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

// 默认连接通过 QUEUE_CONNECTION 切换:
//  - sync (默认):任务在 HTTP 请求线程内联执行,零运维改动
//  - redis:任务派入 Redis,需另起 `php think queue:work --queue=default --tries=3` 常驻进程
return [
    'default'     => env('QUEUE_CONNECTION', 'sync'),
    'connections' => [
        'sync'     => [
            'type' => 'sync',
        ],
        'database' => [
            'type'       => 'database',
            'queue'      => 'default',
            'table'      => 'jobs',
            'connection' => null,
        ],
        'redis'    => [
            'type'       => 'redis',
            'queue'      => env('QUEUE_REDIS_QUEUE', 'default'),
            'host'       => env('QUEUE_REDIS_HOST', '127.0.0.1'),
            'port'       => (int) env('QUEUE_REDIS_PORT', 6379),
            'password'   => env('QUEUE_REDIS_PASSWORD', ''),
            'select'     => (int) env('QUEUE_REDIS_SELECT', 0),
            'timeout'    => (int) env('QUEUE_REDIS_TIMEOUT', 0),
            'persistent' => (bool) env('QUEUE_REDIS_PERSISTENT', false),
        ],
    ],
    'failed'      => [
        'type'  => 'none',
        'table' => 'failed_jobs',
    ],
];
