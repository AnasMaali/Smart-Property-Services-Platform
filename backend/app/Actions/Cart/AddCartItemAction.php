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
use App\Support\Pricing\DefaultCurrency;
use App\Support\Pricing\PricingEngine;
use App\Support\Pricing\PricingStatus;
use App\Support\Pricing\ServiceCapabilities;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates the customer's ACTIVE cart the first time an item is added; every
 * later add reuses it. Lock order is USER -> CART -> (new) CART ITEM ->
 * SELECTIONS throughout, and every write (cart creation, item insert,
 * selection insert) happens only after catalog/selection/pricing validation
 * has fully passed, so a rejected request leaves nothing to roll back.
 */
class AddCartItemAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly CartSelectionValidator $selectionValidator = new CartSelectionValidator,
        private readonly ServiceCapabilities $capabilities = new ServiceCapabilities,
        private readonly PricingEngine $pricingEngine = new PricingEngine,
        private readonly CartPresenter $presenter = new CartPresenter,
    ) {}

    /**
     * @param  array{service_uuid: string, quantity?: int, options?: array<int, array<string, mixed>>}  $data
     * @return array<string, mixed>
     */
    public function handle(string $userUuid, array $data): array
    {
        return DB::transaction(function () use ($userUuid, $data) {
            $userIdBinary = UuidBinary::toBinary($userUuid);

            $user = User::where('id', $userIdBinary)->lockForUpdate()->first();

            if ($user === null) {
                throw new RuntimeException("Authenticated user {$userUuid} not found.");
            }

            $activeStatusId = CartStatuses::id('ACTIVE');

            $cart = Cart::where('customer_user_id', $userIdBinary)
                ->where('status_id', $activeStatusId)
                ->lockForUpdate()
                ->first();

            if ($cart === null) {
                $cart = $this->createActiveCart($userIdBinary, $activeStatusId);
            }

            $serviceUuid = $data['service_uuid'];
            $serviceIdBinary = UuidBinary::toBinary($serviceUuid);

            $service = DB::table('services')->where('id', $serviceIdBinary)->where('is_active', 1)->first(['id']);

            if ($service === null) {
                return $this->notFound('Service not found.');
            }

            if (! $this->capabilities->has($serviceUuid, 'CART_ELIGIBLE')) {
                return $this->unprocessable('This service cannot be added to the cart.');
            }

            $quantity = $data['quantity'] ?? 1;

            $validation = $this->selectionValidator->validate($serviceUuid, $data['options'] ?? []);

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

            $nextDisplayOrder = (int) (CartItem::where('cart_id', UuidBinary::toBinary($cart->id))->max('display_order') ?? -1) + 1;

            $cartItemUuid = UuidBinary::generate();

            $cartItem = CartItem::create([
                'id' => $cartItemUuid,
                'cart_id' => $cart->id,
                'service_id' => $serviceUuid,
                'quantity' => $quantity,
                'display_order' => $nextDisplayOrder,
            ]);

            CartItemSelections::persist(UuidBinary::toBinary($cartItem->id), $validation['options']);

            $cart->last_activity_at = now();
            $cart->save();

            return $this->ok(201, 'Item added to cart.', ['cart' => $this->presenter->present($cart->fresh())]);
        });
    }

    private function createActiveCart(string $userIdBinary, int $activeStatusId): Cart
    {
        $currencyCode = DefaultCurrency::code();
        $currencyId = DB::table('currencies')->where('code', $currencyCode)->value('id');

        $cartUuid = UuidBinary::generate();
        $now = now();

        DB::table('carts')->insert([
            'id' => UuidBinary::toBinary($cartUuid),
            'customer_user_id' => $userIdBinary,
            'status_id' => $activeStatusId,
            'currency_id' => $currencyId,
            'last_activity_at' => $now,
            'status_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return Cart::where('id', UuidBinary::toBinary($cartUuid))->lockForUpdate()->firstOrFail();
    }
}
