<?php

namespace App\Support\Payment;

use App\Models\CartLocation;
use App\Support\Checkout\CheckoutContextResolver;
use Illuminate\Support\Facades\DB;

/**
 * The one reusable, authoritative financial snapshot builder. Built
 * entirely from CheckoutPresenter's own output (App\Support\Checkout\
 * CheckoutPresenter::present()) - reused, never reimplemented - plus the
 * resolved_context codes CheckoutContextResolver already derives for
 * PricingEngine but CheckoutPresenter itself never exposes.
 *
 * Server-side only: CreatePaymentAttemptAction is this class's one caller,
 * always from inside the same locked transaction that already validated
 * ready_for_payment === true. The result is stored verbatim (via
 * CanonicalJson) into payment_attempts.checkout_snapshot and never
 * recomputed afterwards - Phase 7 must build its Booking from this frozen
 * snapshot, not by re-running PricingEngine.
 *
 * Every value here is already the same Flutter-safe shape CheckoutPresenter
 * produces: UUID strings only (no raw binary), no pricing rule ids, no
 * provider/secret/card data.
 */
final class CheckoutSnapshotBuilder
{
    public function __construct(
        private readonly CheckoutContextResolver $contextResolver = new CheckoutContextResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $presentedCheckout  CheckoutPresenter::present($cart) output, already validated ready_for_payment === true.
     * @return array<string, mixed>
     */
    public function build(CartLocation $location, object $heldSlot, array $presentedCheckout): array
    {
        $context = $this->contextResolver->resolve($location, $heldSlot);

        return [
            'cart' => [
                'uuid' => $presentedCheckout['cart_uuid'],
            ],
            'currency' => $presentedCheckout['currency'],
            'requested_total' => $presentedCheckout['total'],
            'location' => $presentedCheckout['location'],
            'appointment' => [
                'hold_uuid' => $presentedCheckout['appointment']['hold_uuid'],
                'slot' => $presentedCheckout['appointment']['slot'],
            ],
            'resolved_context' => [
                'SERVICE_ZONE' => $this->resolveCode('service_zones', $context['SERVICE_ZONE'] ?? null),
                'TIME_WINDOW' => $this->resolveCode('appointment_time_windows', $context['TIME_WINDOW'] ?? null),
            ],
            'items' => array_map(
                static fn (array $item): array => [
                    'cart_item_uuid' => $item['cart_item_uuid'],
                    'service' => [
                        'uuid' => $item['service']['uuid'],
                        'slug' => $item['service']['slug'],
                        'name' => $item['service']['name'],
                    ],
                    'quantity' => $item['quantity'],
                    'options' => $item['options'],
                    'pricing' => $item['pricing'],
                ],
                $presentedCheckout['items'],
            ),
        ];
    }

    private function resolveCode(string $table, ?string $id): ?string
    {
        return $id === null ? null : DB::table($table)->where('id', (int) $id)->value('code');
    }
}
