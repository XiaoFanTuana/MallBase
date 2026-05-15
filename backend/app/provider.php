<?php
use app\ExceptionHandle;
use app\model\sms\SmsConfig;
use app\Request;
use app\service\sms\CacheBackedSmsCache;
use app\service\sms\SmsCache;
use app\service\sms\SmsRateLimiter;
use app\service\sms\SmsService;

// 容器Provider定义文件
return [
    'think\Request'          => Request::class,
    'think\exception\Handle' => ExceptionHandle::class,

    // ---------------- SMS 子系统 ----------------
    // 缓存层:默认走 think\facade\Cache(项目默认 Redis)
    SmsCache::class => CacheBackedSmsCache::class,

    // 频控:从 mb_sms_config 单行表读阈值(替代旧 sms_setting 项)
    SmsRateLimiter::class => function (\think\App $app) {
        $cfg = SmsConfig::singleton();
        return new SmsRateLimiter(
            cache: $app->make(SmsCache::class),
            mobileDailyLimit: (int) $cfg->rate_mobile_daily,
            ipMinuteLimit: (int) $cfg->rate_ip_minute,
        );
    },

    // 业务入口:驱动由 SmsService 按场景绑定动态解析(支持多服务商)
    SmsService::class => function (\think\App $app) {
        $cfg = SmsConfig::singleton();
        return new SmsService(
            driver: null,
            rateLimiter: $app->make(SmsRateLimiter::class),
            cache: $app->make(SmsCache::class),
            codeTtl: (int) $cfg->code_ttl,
        );
    },
];
