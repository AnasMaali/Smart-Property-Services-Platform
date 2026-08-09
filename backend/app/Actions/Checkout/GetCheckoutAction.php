<?php

namespace App\Actions\Checkout;

use App\Models\Cart;
use App\Support\Cart\CartStatuses;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Checkout\CheckoutPresenter;
use App\Support\Uuid\UuidBinary;

/**
 * Read-only: never creates a cart, a location, or a hold as a side effect.
 * Always rebuilds the checkout summary live from the database - see
 * CheckoutPresenter - so it never trusts a previous Cart or Checkout
 * response.
 */
class GetCheckoutAction
{
    use BuildsCartResult;

    public function __construct(private readonly CheckoutPresenter $presenter = new CheckoutPresenter) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $userUuid): array
    {
        $cart = Cart::where('customer_user_id', UuidBinary::toBinary($userUuid))
            ->where('status_id', CartStatuses::id('ACTIVE'))
            ->first();

        $checkoutPayload = $cart === null ? $this->presenter->empty() : $this->presenter->present($cart);

        return $this->ok(200, 'Checkout retrieved successfully.', ['checkout' => $checkoutPayload]);
    }
}
