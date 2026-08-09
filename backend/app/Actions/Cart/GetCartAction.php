<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Support\Cart\CartPresenter;
use App\Support\Cart\CartStatuses;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;

/**
 * Read-only: never creates a cart as a side effect. When the customer has
 * no ACTIVE cart, returns a safe empty-cart representation instead of
 * touching the database with a write.
 */
class GetCartAction
{
    use BuildsCartResult;

    public function __construct(private readonly CartPresenter $presenter = new CartPresenter) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $userUuid): array
    {
        $cart = Cart::where('customer_user_id', UuidBinary::toBinary($userUuid))
            ->where('status_id', CartStatuses::id('ACTIVE'))
            ->first();

        $cartPayload = $cart === null ? $this->presenter->empty() : $this->presenter->present($cart);

        return $this->ok(200, 'Cart retrieved successfully.', ['cart' => $cartPayload]);
    }
}
