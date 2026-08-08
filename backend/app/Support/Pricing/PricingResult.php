<?php

namespace App\Support\Pricing;

/**
 * The one stable, authoritative pricing output contract. Every caller -
 * service-details preview, cart add/update/get, checkout validation, and
 * (later) booking-snapshot creation - consumes exactly this shape so there
 * is never more than one calculation algorithm in the system.
 *
 * Amounts are kept as decimal strings (never float) to preserve the
 * database's decimal(19,6) precision, matching the convention already used
 * by the Phase 3 catalog API.
 */
final readonly class PricingResult
{
    /**
     * @param  array<int, PricingAdjustment>  $adjustments
     * @param  array<int, string>  $requiredContext
     */
    public function __construct(
        public PricingStatus $status,
        public ?string $currencyCode,
        public ?string $pricingSchemeVersion,
        public ?string $baseAmount,
        public array $adjustments,
        public ?string $unitTotal,
        public int $quantity,
        public ?string $lineTotal,
        public array $requiredContext = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pricing_status' => $this->status->value,
            'currency' => $this->currencyCode,
            'pricing_scheme_version' => $this->pricingSchemeVersion,
            'base_amount' => $this->baseAmount,
            'adjustments' => array_map(fn (PricingAdjustment $adjustment) => $adjustment->toArray(), $this->adjustments),
            'unit_total' => $this->unitTotal,
            'quantity' => $this->quantity,
            'line_total' => $this->lineTotal,
            'required_context' => $this->requiredContext,
        ];
    }
}
