<?php

declare (strict_types=1);

// 文件上传配置
return [
    // 默认上传驱动：local（本地）、oss（阿里云OSS）、cos（腾讯云COS）
    'driver' => 'local',

    // 本地存储配置
    'local' => [
        'root_path' => '',
        'url_prefix' => '/uploads',
        'base_url' => 'http://127.0.0.1:8080', // 完整的基础URL，用于返回给前端
    ],

    // 阿里云 OSS 配置
    'oss' => [
        'accessKeyId' => '',
        'accessKeySecret' => '',
        'bucket' => '',
        'endpoint' => '',
        'urlPrefix' => '',
    ],

    // 腾讯云 COS 配置
    'cos' => [
        'secretId' => '',
        'secretKey' => '',
        'region' => '',
        'bucket' => '',
        'urlPrefix' => '',
    ],
];