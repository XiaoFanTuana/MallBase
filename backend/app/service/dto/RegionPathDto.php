<?php

declare(strict_types=1);

namespace app\service\dto;

/**
 * 地址四级路径 DTO（运费计算入参）
 *
 * 用于承载运费计算时需要的地址上下文：从省到街道四级 ID 均为必填，
 * 便于匹配规则时按层级（街道 > 区 > 市 > 省）依次回退。
 */
final class RegionPathDto
{
    public function __construct(
        public readonly int $provinceId,
        public readonly int $cityId,
        public readonly int $districtId,
        public readonly int $streetId,
    ) {
    }

    /**
     * 按层级取对应区域 ID（1=省 2=市 3=区 4=街道），越界返回 0
     */
    public function idByLevel(int $level): int
    {
        return match ($level) {
            1 => $this->provinceId,
            2 => $this->cityId,
            3 => $this->districtId,
            4 => $this->streetId,
            default => 0,
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            provinceId: (int) ($data['province_id'] ?? 0),
            cityId: (int) ($data['city_id'] ?? 0),
            districtId: (int) ($data['district_id'] ?? 0),
            streetId: (int) ($data['street_id'] ?? 0),
        );
    }
}
