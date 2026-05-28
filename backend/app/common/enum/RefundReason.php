<?php
declare(strict_types=1);

namespace app\common\enum;

use app\service\order\OrderSettingService;

/**
 * 售后申请原因枚举
 *
 * 设计说明：
 *  - 以字符串常量落库，避免后续语义漂移（对比 tinyint，更利于日志与数据分析）
 *  - 前端下拉仅展示本枚举范围，后端 Validate 同样用 in: 规则收敛
 *  - MVP 仅覆盖最典型四类原因；新增时务必同步更新 TEXTS 与 options()
 */
class RefundReason
{
    /** 订单下错了（买家误操作） */
    public const MISTAKEN_ORDER = 'MISTAKEN_ORDER';

    /** 商品质量问题 */
    public const QUALITY_ISSUE = 'QUALITY_ISSUE';

    /** 不想要了 */
    public const NO_LONGER_WANTED = 'NO_LONGER_WANTED';

    /** 其他 */
    public const OTHER = 'OTHER';

    private const TEXTS = [
        self::MISTAKEN_ORDER    => '订单拍错',
        self::QUALITY_ISSUE     => '商品质量问题',
        self::NO_LONGER_WANTED  => '不想要了',
        self::OTHER             => '其他',
    ];

    public static function textOf(string $reason): string
    {
        $setting = self::settingService();
        if ($setting !== null) {
            return $setting->refundReasonText($reason);
        }
        return self::TEXTS[$reason] ?? '未知';
    }

    public static function isValid(string $reason): bool
    {
        $setting = self::settingService();
        if ($setting !== null) {
            return $setting->isValidRefundReason($reason);
        }
        return isset(self::TEXTS[$reason]);
    }

    /**
     * 可用原因值集合（供 Validate in: 规则使用）
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        $setting = self::settingService();
        if ($setting !== null) {
            return $setting->refundReasonValues();
        }
        return array_keys(self::TEXTS);
    }

    /**
     * @return array<int, array{value:string, label:string}>
     */
    public static function options(): array
    {
        $setting = self::settingService();
        if ($setting !== null) {
            return $setting->refundReasonOptions();
        }
        return array_map(
            static fn(string $value, string $label): array => ['value' => $value, 'label' => $label],
            array_keys(self::TEXTS),
            array_values(self::TEXTS)
        );
    }

    private static function settingService(): ?OrderSettingService
    {
        if (!function_exists('app')) {
            return null;
        }

        try {
            return \app()->make(OrderSettingService::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
