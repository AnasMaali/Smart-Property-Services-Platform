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
use InvalidArgumentException;
use RuntimeException;

/**
 * Removes one owned cart item. Selection rows cascade at the database layer
 * (ON DELETE CASCADE on both cart_item_option_selections and
 * cart_item_option_choice_selections) - the cart row itself is never
 * touched. Lock order: USER -> CART -> ITEM.
 */
class RemoveCartItemAction
{
    use BuildsCartResult;

    private const NOT_FOUND_MESSAGE = 'Cart item not found.';

    public function __construct(private readonly CartPresenter $presenter = new CartPresenter) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $userUuid, string $itemUuid): array
    {
        return DB::transaction(function () use ($userUuid, $itemUuid) {
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
                return $this->notFound(self::NOT_FOUND_MESSAGE);
            }

            try {
                $itemIdBinary = UuidBinary::toBinary($itemUuid);
            } catch (InvalidArgumentException) {
                return $this->notFound(self::NOT_FOUND_MESSAGE);
            }

            $item = CartItem::where('id', $itemIdBinary)->where('cart_id', UuidBinary::toBinary($cart->id))->lockForUpdate()->first();

            if ($item === null) {
                return $this->notFound(self::NOT_FOUND_MESSAGE);
            }

            $item->delete();

            $cart->last_activity_at = now();
            $cart->save();

            return $this->ok(200, 'Item removed from cart.', ['cart' => $this->presenter->present($cart->fresh())]);
        });
    }
}
