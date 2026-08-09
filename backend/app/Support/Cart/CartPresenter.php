<?php

namespace App\Support\Cart;

use App\Models\Cart;
use App\Support\Pricing\DefaultCurrency;
use App\Support\Pricing\PricingEngine;
use App\Support\Pricing\PricingResult;
use App\Support\Pricing\PricingResultAggregator;
use App\Support\Pricing\PricingStatus;
use App\Support\ServiceCatalog\ServiceSummaryPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * Builds the one safe, Flutter-facing cart JSON shape shared by every
 * mutating endpoint's response and by GET /cart - each call independently
 * reloads items and selections from the database and reprices every item
 * through the same PricingEngine::evaluate(), so the payload always
 * reflects live, authoritative pricing and never a client- or
 * previously-computed amount.
 */
final class CartPresenter
{
    public function __construct(private readonly PricingEngine $pricingEngine = new PricingEngine) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Cart $cart): array
    {
        $currency = DB::table('currencies')->where('id', $cart->currency_id)->first(['code', 'symbol', 'minor_unit']);

        $items = DB::table('cart_items')
            ->where('cart_id', UuidBinary::toBinary($cart->id))
            ->orderBy('display_order')
            ->orderBy('created_at')
            ->get(['id', 'service_id', 'quantity']);

        $itemPayloads = [];
        $pricingResults = [];

        foreach ($items as $item) {
            $optionsInput = CartItemSelections::loadAsInput($item->id);
            $pricingSelections = CartItemSelections::loadPricingSelections($item->id);

            $pricingResult = $this->pricingEngine->evaluate(
                serviceIdUuid: UuidBinary::toString($item->service_id),
                selections: $pricingSelections,
                quantity: (int) $item->quantity,
                currencyCode: $currency->code,
            );

            $pricingResults[] = $pricingResult;

            $itemPayloads[] = [
                'uuid' => UuidBinary::toString($item->id),
                'service' => ServiceSummaryPresenter::present($item->service_id),
                'quantity' => (int) $item->quantity,
                'options' => $optionsInput,
                'pricing' => $pricingResult->toArray(),
            ];
        }

        $aggregate = $this->aggregate($pricingResults);

        return [
            'uuid' => $cart->id,
            'currency' => [
                'code' => $currency->code,
                'symbol' => $currency->symbol,
                'decimal_places' => (int) $currency->minor_unit,
            ],
            'pricing_status' => $aggregate['status']->value,
            'required_context' => $aggregate['required_context'],
            'requires_quote' => $aggregate['requires_quote'],
            'items' => $itemPayloads,
            'total' => $aggregate['total'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function empty(): array
    {
        $currencyCode = DefaultCurrency::code();
        $currency = DB::table('currencies')->where('code', $currencyCode)->first(['code', 'symbol', 'minor_unit']);

        return [
            'uuid' => null,
            'currency' => [
                'code' => $currency->code,
                'symbol' => $currency->symbol,
                'decimal_places' => (int) $currency->minor_unit,
            ],
            'pricing_status' => PricingStatus::PRICED->value,
            'required_context' => [],
            'requires_quote' => false,
            'items' => [],
            'total' => '0.000000',
        ];
    }

    /**
     * Cart-aggregate pricing status per BLUE V1 Phase 4 §8: QUOTE_REQUIRED
     * beats MISSING_CONTEXT beats UNAVAILABLE beats PRICED. An empty cart is
     * trivially PRICED with a zero total. Delegates to the shared
     * PricingResultAggregator so Checkout (Phase 5) reuses the exact same
     * roll-up instead of a second copy of it.
     *
     * @param  array<int, PricingResult>  $pricingResults
     * @return array{status: PricingStatus, required_context: array<int, string>, requires_quote: bool, total: ?string}
     */
    private function aggregate(array $pricingResults): array
    {
        return PricingResultAggregator::aggregate($pricingResults);
    }
}
