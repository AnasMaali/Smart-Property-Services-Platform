<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use App\Support\Cart\CartPresenter;
use App\Support\Cart\CartStatuses;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Removes every item from the customer's ACTIVE cart but keeps the cart row
 * itself - clearing an empty cart, or a customer with no cart at all, is a
 * safe no-op rather than an error. Lock order: USER -> CART.
 */
class ClearCartAction
{
    use BuildsCartResult;

    public function __construct(private readonly CartPresenter $presenter = new CartPresenter) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $userUuid): array
    {
        return DB::transaction(function () use ($userUuid) {
            $userIdBinary = UuidBinary::toBinary($userUuid);

            $user = User::where('id', $userIdBinary)->lockForUpdate()->first();

            if ($user === null) {
                throw new RuntimeException("Authenticated user {$userUuid} not found.");
            }

            $cart = Cart::where('customer_user_id', $userIdBinary)
                ->where('status_id', CartStatuses::id('ACTIVE'))
                ->lockForUpdate()
                ->first();

            if ($cart === null) {
                return $this->ok(200, 'Cart is already empty.', ['cart' => $this->presenter->empty()]);
            }

            CartItem::where('cart_id', UuidBinary::toBinary($cart->id))->delete();

            $cart->last_activity_at = now();
            $cart->save();

            return $this->ok(200, 'Cart cleared.', ['cart' => $this->presenter->present($cart->fresh())]);
        });
    }
}
