<?php

namespace App\Support\Checkout;

use App\Support\Payment\ServicePaymentPolicy;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Collection;

/**
 * BLUE V1 Phase B24 - the ONE canonical mixed-Cart payment-method
 * intersection calculation. For a Cart containing Services with different
 * allowed-methods policies, the checkout-available methods are the
 * INTERSECTION of every distinct Service's active allowed methods (never a
 * union, never "any item allows it") - e.g. Service A allows CARD/APPLE_PAY/
 * PAY_ON_SITE and Service B allows only CARD/APPLE_PAY -> the Cart may only
 * checkout with CARD or APPLE_PAY. An empty Cart has no items to constrain,
 * so it trivially allows every active payment method type (never used to
 * actually pay for anything in that state - App\Support\Checkout\
 * CheckoutPresenter's own `ready_for_payment` gate already requires at
 * least one item). Deliberately never computed twice: Flutter must always
 * read this from the Checkout API, never recompute it client-side.
 */
final class CheckoutPaymentPolicy
{
    /**
     * @param  array<int, string>  $serviceUuids  Every distinct Service UUID currently in the Cart.
     * @return array{codes: array<int, string>, methods: array<int, array{code: string, name: string}>, requires_prepayment: bool}
     */
    public static function availableMethodsFor(array $serviceUuids): array
    {
        $distinctServiceUuids = array_values(array_unique($serviceUuids));

        if ($distinctServiceUuids === []) {
            return ['codes' => [], 'methods' => [], 'requires_prepayment' => true];
        }

        $serviceIdsBinary = array_map(UuidBinary::toBinary(...), $distinctServiceUuids);
        $allowedByService = ServicePaymentPolicy::allowedMethodsForMany($serviceIdsBinary);

        /** @var ?Collection<int, array{code: string, name: string}> $intersection */
        $intersection = null;

        foreach ($serviceIdsBinary as $serviceIdBinary) {
            $methods = $allowedByService->get(bin2hex($serviceIdBinary), collect());

            $intersection = $intersection === null
                ? $methods
                : $intersection->filter(fn ($m) => $methods->contains(fn ($n) => $n['code'] === $m['code']));
        }

        $methods = ($intersection ?? collect())->values();
        $codes = $methods->pluck('code')->all();

        return [
            'codes' => $codes,
            'methods' => $methods->all(),
            'requires_prepayment' => ServicePaymentPolicy::requiresPrepayment($codes),
        ];
    }
}
