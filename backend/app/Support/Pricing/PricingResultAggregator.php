<?php

namespace App\Support\Pricing;

/**
 * The one worst-status-wins roll-up over a list of PricingResult, shared by
 * every caller that presents more than one priced line at once (Cart,
 * Checkout): QUOTE_REQUIRED beats MISSING_CONTEXT beats UNAVAILABLE beats
 * PRICED. An empty list is trivially PRICED with a zero total. Extracted
 * from the Phase 4 CartPresenter so Checkout reuses the exact same
 * aggregate semantics instead of a second copy of this logic.
 */
final class PricingResultAggregator
{
    /**
     * @param  array<int, PricingResult>  $pricingResults
     * @return array{status: PricingStatus, required_context: array<int, string>, requires_quote: bool, total: ?string}
     */
    public static function aggregate(array $pricingResults): array
    {
        $requiredContext = [];
        $requiresQuote = false;
        $hasQuoteRequired = false;
        $hasMissingContext = false;
        $hasUnavailable = false;

        foreach ($pricingResults as $result) {
            $requiredContext = array_merge($requiredContext, $result->requiredContext);

            match ($result->status) {
                PricingStatus::QUOTE_REQUIRED => $hasQuoteRequired = $requiresQuote = true,
                PricingStatus::MISSING_CONTEXT => $hasMissingContext = true,
                PricingStatus::UNAVAILABLE => $hasUnavailable = true,
                PricingStatus::PRICED => null,
            };
        }

        $requiredContext = array_values(array_unique($requiredContext));
        sort($requiredContext);

        $status = match (true) {
            $hasQuoteRequired => PricingStatus::QUOTE_REQUIRED,
            $hasMissingContext => PricingStatus::MISSING_CONTEXT,
            $hasUnavailable => PricingStatus::UNAVAILABLE,
            default => PricingStatus::PRICED,
        };

        $total = null;

        if ($status === PricingStatus::PRICED) {
            $total = '0.000000';

            foreach ($pricingResults as $result) {
                $total = bcadd($total, $result->lineTotal ?? '0', 6);
            }
        }

        return [
            'status' => $status,
            'required_context' => $requiredContext,
            'requires_quote' => $requiresQuote,
            'total' => $total,
        ];
    }
}
