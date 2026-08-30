<?php

namespace App\Support\Checkout;

use App\Models\Cart;
use App\Models\CartLocation;
use App\Support\Cart\CartItemSelections;
use App\Support\Pricing\DefaultCurrency;
use App\Support\Pricing\PricingEngine;
use App\Support\Pricing\PricingResultAggregator;
use App\Support\Pricing\PricingStatus;
use App\Support\ServiceCatalog\ServiceSummaryPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the one safe, Flutter-facing checkout JSON shape (§12 of
 * docs/api-contracts/checkout-v1.md), always re-reading the cart, its
 * location, and its current appointment hold straight from the database and
 * repricing every item live through PricingEngine::evaluate() - the same
 * "never trust a previous response" contract Cart already established.
 * Never mutates anything; every write lives in an Action instead.
 */
final class CheckoutPresenter
{
    public function __construct(
        private readonly PricingEngine $pricingEngine = new PricingEngine,
        private readonly CheckoutContextResolver $contextResolver = new CheckoutContextResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Cart $cart): array
    {
        $currency = DB::table('currencies')->where('id', $cart->currency_id)->first(['code', 'symbol', 'minor_unit']);

        $location = CartLocation::where('cart_id', UuidBinary::toBinary($cart->id))->first();
        $hold = $this->activeHold($cart->id);
        $context = $this->contextResolver->resolve($location, $hold);

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
                context: $context,
            );

            $pricingResults[] = $pricingResult;

            $itemPayloads[] = [
                'cart_item_uuid' => UuidBinary::toString($item->id),
                'service' => ServiceSummaryPresenter::present($item->service_id),
                'quantity' => (int) $item->quantity,
                'options' => $optionsInput,
                'pricing' => $pricingResult->toArray(),
            ];
        }

        $aggregate = PricingResultAggregator::aggregate($pricingResults);

        $readyForPayment = $itemPayloads !== []
            && $location !== null
            && $hold !== null
            && $aggregate['status'] === PricingStatus::PRICED;

        $paymentPolicy = CheckoutPaymentPolicy::availableMethodsFor(
            $items->map(fn ($item) => UuidBinary::toString($item->service_id))->all(),
        );

        return [
            'cart_uuid' => $cart->id,
            'location' => $location === null ? null : $this->locationPayload($location),
            'appointment' => $hold === null ? null : $this->appointmentPayload($hold),
            'pricing_status' => $aggregate['status']->value,
            'required_context' => $aggregate['required_context'],
            'requires_quote' => $aggregate['requires_quote'],
            'ready_for_payment' => $readyForPayment,
            'currency' => [
                'code' => $currency->code,
                'symbol' => $currency->symbol,
                'decimal_places' => (int) $currency->minor_unit,
            ],
            'items' => $itemPayloads,
            'total' => $aggregate['total'],
            'available_payment_methods' => $paymentPolicy['methods'],
            'requires_prepayment' => $paymentPolicy['requires_prepayment'],
        ];
    }

    /**
     * Safe representation for a customer with no ACTIVE cart - mirrors
     * Cart's GetCartAction, which never creates a cart as a side effect.
     *
     * @return array<string, mixed>
     */
    public function empty(): array
    {
        $currencyCode = DefaultCurrency::code();
        $currency = DB::table('currencies')->where('code', $currencyCode)->first(['code', 'symbol', 'minor_unit']);

        return [
            'cart_uuid' => null,
            'location' => null,
            'appointment' => null,
            'pricing_status' => PricingStatus::PRICED->value,
            'required_context' => [],
            'requires_quote' => false,
            'ready_for_payment' => false,
            'currency' => [
                'code' => $currency->code,
                'symbol' => $currency->symbol,
                'decimal_places' => (int) $currency->minor_unit,
            ],
            'items' => [],
            'total' => '0.000000',
            'available_payment_methods' => [],
            'requires_prepayment' => true,
        ];
    }

    /**
     * The cart's current usable hold: not released, not converted, not
     * expired. An expired hold is reported as "no appointment" (§7 of the
     * Phase 5 spec: "expired hold is unusable") without ever mutating it -
     * GET stays read-only, matching Cart's convention.
     */
    private function activeHold(string $cartIdUuid): ?object
    {
        return DB::table('appointment_holds')
            ->join('appointment_slots', 'appointment_slots.id', '=', 'appointment_holds.appointment_slot_id')
            ->where('appointment_holds.cart_id', UuidBinary::toBinary($cartIdUuid))
            ->whereNull('appointment_holds.released_at')
            ->whereNull('appointment_holds.converted_at')
            ->where('appointment_holds.expires_at', '>', now())
            ->orderByDesc('appointment_holds.created_at')
            ->first([
                'appointment_holds.id as hold_id',
                'appointment_holds.expires_at as hold_expires_at',
                'appointment_slots.id as slot_id',
                'appointment_slots.starts_at as slot_starts_at',
                'appointment_slots.ends_at as slot_ends_at',
                'appointment_slots.time_window_id as time_window_id',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentPayload(object $hold): array
    {
        return [
            'hold_uuid' => UuidBinary::toString($hold->hold_id),
            'slot' => [
                'uuid' => UuidBinary::toString($hold->slot_id),
                'starts_at' => Carbon::parse($hold->slot_starts_at)->toIso8601String(),
                'ends_at' => Carbon::parse($hold->slot_ends_at)->toIso8601String(),
                'time_window' => $this->timeWindowPayload((int) $hold->time_window_id),
            ],
            'expires_at' => Carbon::parse($hold->hold_expires_at)->toIso8601String(),
        ];
    }

    /**
     * Safe display label only - never used as a pricing input. TIME_WINDOW's
     * actual resolution (active-only) lives in CheckoutContextResolver.
     *
     * @return array<string, mixed>|null
     */
    private function timeWindowPayload(int $timeWindowId): ?array
    {
        $timeWindow = DB::table('appointment_time_windows')->where('id', $timeWindowId)->first(['code', 'name']);

        return $timeWindow === null ? null : [
            'code' => $timeWindow->code,
            'name' => $timeWindow->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function locationPayload(CartLocation $location): array
    {
        $propertyType = DB::table('property_types')->where('id', $location->property_type_id)->first(['id', 'code', 'name']);
        $area = DB::table('areas')->where('id', $location->area_id)->first(['id', 'code', 'name', 'city_id']);
        $city = $area === null ? null : DB::table('cities')->where('id', $area->city_id)->first(['id', 'code', 'name']);

        return [
            'property_type' => $propertyType === null ? null : [
                'id' => (int) $propertyType->id,
                'code' => $propertyType->code,
                'name' => $propertyType->name,
            ],
            'other_property_type_name' => $location->other_property_type_name,
            'area' => $area === null ? null : [
                'id' => (int) $area->id,
                'code' => $area->code,
                'name' => $area->name,
            ],
            'city' => $city === null ? null : [
                'id' => (int) $city->id,
                'code' => $city->code,
                'name' => $city->name,
            ],
            'street_name' => $location->street_name,
            'address_line' => $location->address_line,
            'building_name_or_number' => $location->building_name_or_number,
            'floor_number' => $location->floor_number,
            'unit_number' => $location->unit_number,
            'nearby_landmark' => $location->nearby_landmark,
            'additional_location_notes' => $location->additional_location_notes,
            'visit_contact_phone' => $location->visit_contact_phone,
        ];
    }
}
