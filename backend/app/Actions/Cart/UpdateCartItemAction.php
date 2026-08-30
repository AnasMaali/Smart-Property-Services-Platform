<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use App\Support\Cart\CartItemSelections;
use App\Support\Cart\CartPresenter;
use App\Support\Cart\CartSelectionValidator;
use App\Support\Cart\CartStatuses;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Catalog\ServiceQuantityPolicy;
use App\Support\Pricing\PricingEngine;
use App\Support\Pricing\PricingStatus;
use App\Support\Pricing\ServiceCapabilities;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Full revalidation + repricing on every update, matching Add: the
 * submitted (or, when `options` is omitted, the currently-persisted)
 * selection set is always re-checked against the live catalog schema and
 * re-evaluated through PricingEngine before anything is written. Lock order
 * is USER -> CART -> CART ITEM; a UUID that does not resolve to an item
 * owned by this customer's ACTIVE cart is rejected as not-found, never
 * distinguished from "belongs to someone else".
 */
class UpdateCartItemAction
{
    use BuildsCartResult;

    private const NOT_FOUND_MESSAGE = 'Cart item not found.';

    public function __construct(
        private readonly CartSelectionValidator $selectionValidator = new CartSelectionValidator,
        private readonly ServiceCapabilities $capabilities = new ServiceCapabilities,
        private readonly PricingEngine $pricingEngine = new PricingEngine,
        private readonly CartPresenter $presenter = new CartPresenter,
    ) {}

    /**
     * @param  array{quantity?: int, options?: array<int, array<string, mixed>>}  $data
     * @return array<string, mixed>
     */
    public function handle(string $userUuid, string $itemUuid, array $data): array
    {
        return DB::transaction(function () use ($userUuid, $itemUuid, $data) {
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

            $serviceUuid = $item->service_id;
            $service = DB::table('services')->where('id', UuidBinary::toBinary($serviceUuid))->where('is_active', 1)->first(['id', 'min_quantity', 'max_quantity']);

            if ($service === null) {
                return $this->unprocessable('This service is no longer available.');
            }

            if (! $this->capabilities->has($serviceUuid, 'CART_ELIGIBLE')) {
                return $this->unprocessable('This service can no longer be purchased through the cart.');
            }

            $quantity = $data['quantity'] ?? $item->quantity;

            $quantityError = ServiceQuantityPolicy::violation($quantity, (int) $service->min_quantity, (int) $service->max_quantity);

            if ($quantityError !== null) {
                return $this->unprocessable($quantityError, ['quantity' => [$quantityError]]);
            }

            $optionsInput = array_key_exists('options', $data)
                ? $data['options']
                : CartItemSelections::loadAsInput($itemIdBinary);

            $validation = $this->selectionValidator->validate($serviceUuid, $optionsInput);

            if ($validation['errors'] !== []) {
                return $this->unprocessable('One or more selected options are invalid.', $validation['errors']);
            }

            $currencyCode = DB::table('currencies')->where('id', $cart->currency_id)->value('code');

            $pricingResult = $this->pricingEngine->evaluate(
                serviceIdUuid: $serviceUuid,
                selections: $this->selectionValidator->toPricingSelections($validation['options']),
                quantity: $quantity,
                currencyCode: $currencyCode,
            );

            if ($pricingResult->status === PricingStatus::UNAVAILABLE) {
                return $this->unprocessable('This service is not currently available for purchase.');
            }

            $item->quantity = $quantity;
            $item->save();

            if (array_key_exists('options', $data)) {
                CartItemSelections::replace($itemIdBinary, $validation['options']);
            }

            $cart->last_activity_at = now();
            $cart->save();

            return $this->ok(200, 'Cart item updated.', ['cart' => $this->presenter->present($cart->fresh())]);
        });
    }
}
