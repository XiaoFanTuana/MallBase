<?php

declare(strict_types=1);

namespace app\model\sms;

use mall_base\base\BaseModel;

/**
 * 短信全局配置(单行)
 *
 * 替代旧 setting 项 sms_code_ttl / sms_rate_mobile_daily / sms_rate_ip_minute。
 * 永远只有 id=1 一行,SmsConfigService 负责 upsert。
 */
class SmsConfig extends BaseModel
{
    protected $name = 'sms_config';

    protected $autoWriteTimestamp = true;

    /** 单例主键 */
    public const SINGLETON_ID = 1;

    /**
     * 拿到单例配置(未初始化时返回默认值)
     */
    public static function singleton(): self
    {
        $row = static::find(self::SINGLETON_ID);
        if ($row === null) {
            $row = new self([
                'id' => self::SINGLETON_ID,
                'code_ttl' => 300,
                'rate_mobile_daily' => 5,
                'rate_ip_minute' => 3,
            ]);
        }
        return $row;
    }
}
