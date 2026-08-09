<?php

namespace App\Actions\Checkout;

use App\Models\Cart;
use App\Models\User;
use App\Support\Cart\CartStatuses;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Checkout\CheckoutPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Releases the ACTIVE cart's current open hold, if any - always resolved
 * from the authenticated customer's own cart, never from a client-supplied
 * hold_uuid, so there is no ownership check to bypass: another customer's
 * hold is simply never reachable through this endpoint. A cart with no
 * open hold is a safe no-op, matching Cart's ClearCartAction convention.
 * Lock order: USER -> CART -> HOLD.
 */
class ReleaseAppointmentHoldAction
{
    use BuildsCartResult;

    public function __construct(private readonly CheckoutPresenter $presenter = new CheckoutPresenter) {}

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
                return $this->ok(200, 'No active cart.', ['checkout' => $this->presenter->empty()]);
            }

            $now = now();

            // Preserve microsecond precision: the query builder truncates
            // DateTimeInterface bindings to whole seconds (see
            // Illuminate\Database\Connection::prepareBindings()), but held_at is
            // stored with microsecond precision, and chk_appointment_holds_released_at
            // requires released_at >= held_at.
            $timestamp = $now->format('Y-m-d H:i:s.u');

            $released = DB::table('appointment_holds')
                ->where('cart_id', UuidBinary::toBinary($cart->id))
                ->whereNull('released_at')
                ->whereNull('converted_at')
                ->update(['released_at' => $timestamp, 'updated_at' => $timestamp]);

            $cart->last_activity_at = $now;
            $cart->save();

            $message = $released > 0 ? 'Appointment hold released.' : 'No active appointment hold to release.';

            return $this->ok(200, $message, ['checkout' => $this->presenter->present($cart->fresh())]);
        });
    }
}
