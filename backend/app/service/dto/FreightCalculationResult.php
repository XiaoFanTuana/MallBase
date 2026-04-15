<?php

declare(strict_types=1);

namespace app\service\dto;

/**
 * 运费计算结果 DTO
 *
 * - fee：计算得出的运费金额（保留 2 位小数，向上取整到分）
 * - source：命中来源 rule | default | free
 *   - rule: 命中某条规则
 *   - default: 未命中规则，回退到模板默认运费
 *   - free: 模板 id=0（包邮占位）
 * - matchedRuleId：命中规则主键；未命中为 null
 * - matchedLevel：命中层级（1省 2市 3区 4街道）；未命中为 null
 */
final class FreightCalculationResult
{
    /**
     * @param 'rule'|'default'|'free' $source
     */
    public function __construct(
        public readonly float $fee,
        public readonly string $source,
        public readonly ?int $matchedRuleId = null,
        public readonly ?int $matchedLevel = null,
    ) {
    }

    public static function free(): self
    {
        return new self(fee: 0.0, source: 'free');
    }

    public static function default(float $fee): self
    {
        return new self(fee: $fee, source: 'default');
    }

    public static function rule(float $fee, int $ruleId, int $level): self
    {
        return new self(fee: $fee, source: 'rule', matchedRuleId: $ruleId, matchedLevel: $level);
    }

    /**
     * @return array{fee: float, source: string, matched_rule_id: int|null, matched_level: int|null}
     */
    public function toArray(): array
    {
        return [
            'fee' => $this->fee,
            'source' => $this->source,
            'matched_rule_id' => $this->matchedRuleId,
            'matched_level' => $this->matchedLevel,
        ];
    }
}
