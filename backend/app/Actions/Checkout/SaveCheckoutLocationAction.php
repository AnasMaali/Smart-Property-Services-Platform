<?php

namespace App\Actions\Checkout;

use App\Models\Cart;
use App\Models\CartLocation;
use App\Models\User;
use App\Support\Cart\CartStatuses;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Checkout\CheckoutPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Persists the ACTUAL service location for the customer's ACTIVE cart -
 * never derived from the customer's profile address (see
 * docs/api-contracts/checkout-v1.md). cart_locations.cart_id is the primary
 * key (1:1 with carts), so this is always an upsert: one row per cart,
 * never a duplicate. Lock order: USER -> CART -> CART_LOCATION.
 */
class SaveCheckoutLocationAction
{
    use BuildsCartResult;

    public function __construct(private readonly CheckoutPresenter $presenter = new CheckoutPresenter) {}

    /**
     * @param  array<string, mixed>  $data
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

            $cart = Cart::where('customer_user_id', $userIdBinary)
                ->where('status_id', CartStatuses::id('ACTIVE'))
                ->lockForUpdate()
                ->first();

            if ($cart === null) {
                return $this->notFound('No active cart to check out.');
            }

            $propertyType = DB::table('property_types')
                ->where('id', $data['property_type_id'])
                ->where('is_active', 1)
                ->first(['id', 'code']);

            if ($propertyType === null) {
                return $this->unprocessable('One or more location fields are invalid.', ['The selected property type is invalid or inactive.']);
            }

            $otherPropertyTypeName = trim((string) ($data['other_property_type_name'] ?? ''));

            if ($propertyType->code === 'OTHER' && $otherPropertyTypeName === '') {
                return $this->unprocessable('One or more location fields are invalid.', ['other_property_type_name is required when property_type_id is OTHER.']);
            }

            $cartIdBinary = UuidBinary::toBinary($cart->id);
            $now = now();

            $existingLocation = CartLocation::where('cart_id', $cartIdBinary)->lockForUpdate()->first();

            $fields = [
                'property_type_id' => $propertyType->id,
                'area_id' => $data['area_id'],
                'other_property_type_name' => $propertyType->code === 'OTHER' ? $otherPropertyTypeName : null,
                'street_name' => $data['street_name'],
                'address_line' => $data['address_line'],
                'building_name_or_number' => $data['building_name_or_number'],
                'floor_number' => $data['floor_number'] ?? null,
                'unit_number' => $data['unit_number'] ?? null,
                'nearby_landmark' => $data['nearby_landmark'] ?? null,
                'additional_location_notes' => $data['additional_location_notes'] ?? null,
                'visit_contact_phone' => $data['visit_contact_phone'],
                'updated_at' => $now,
            ];

            if ($existingLocation === null) {
                DB::table('cart_locations')->insert(['cart_id' => $cartIdBinary, 'created_at' => $now, ...$fields]);
            } else {
                DB::table('cart_locations')->where('cart_id', $cartIdBinary)->update($fields);
            }

            $cart->last_activity_at = $now;
            $cart->save();

            return $this->ok(200, 'Checkout location saved.', ['checkout' => $this->presenter->present($cart->fresh())]);
        });
    }
}
