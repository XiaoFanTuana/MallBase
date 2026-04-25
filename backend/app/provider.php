<?php
use app\ExceptionHandle;
use app\Request;
use app\service\sms\CacheBackedSmsCache;
use app\service\sms\SmsCache;
use app\service\sms\SmsRateLimiter;
use app\service\sms\SmsService;
use mall_base\drivers\DriverManager;

// 容器Provider定义文件
return [
    'think\Request'          => Request::class,
    'think\exception\Handle' => ExceptionHandle::class,

    // ---------------- SMS 子系统 ----------------
    // 缓存层:默认走 think\facade\Cache(项目默认 Redis)
    SmsCache::class => CacheBackedSmsCache::class,

    // 频控:从 config 读取阈值
    SmsRateLimiter::class => function (\think\App $app) {
        return new SmsRateLimiter(
            cache: $app->make(SmsCache::class),
            mobileDailyLimit: (int) config('sms.rate_limit.mobile_daily', 5),
            ipMinuteLimit: (int) config('sms.rate_limit.ip_minute', 3),
        );
    },

    // 业务入口:驱动通过 DriverManager 获取
    SmsService::class => function (\think\App $app) {
        $driver = DriverManager::driver('sms', null, config('sms.aliyun', []));
        return new SmsService(
            driver: $driver,
            rateLimiter: $app->make(SmsRateLimiter::class),
            cache: $app->make(SmsCache::class),
            codeTtl: (int) config('sms.code_ttl', 300),
        );
    },
];
