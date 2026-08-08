<?php

namespace App\Support\Pricing;

/**
 * One safe, displayable line in a PricingResult's adjustments[] breakdown.
 * Never carries raw condition data, internal rule ids, or binary values -
 * only what Flutter is allowed to render.
 */
final readonly class PricingAdjustment
{
    public function __construct(
        public string $ruleCode,
        public string $label,
        public EffectType $effectType,
        public ?string $amountOrFactor,
        public ?string $runningTotalAfter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule_code' => $this->ruleCode,
            'label' => $this->label,
            'effect_type' => $this->effectType->value,
            'amount_or_factor' => $this->amountOrFactor,
            'running_total_after' => $this->runningTotalAfter,
        ];
    }
}
