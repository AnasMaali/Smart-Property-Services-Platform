<?php

namespace App\Actions\Checkout;

use App\Models\AppointmentSlot;
use App\Models\Cart;
use App\Models\User;
use App\Support\Cart\CartStatuses;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Checkout\AppointmentSlotOccupancy;
use App\Support\Checkout\CheckoutPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Holds exactly one appointment slot for the customer's ACTIVE cart,
 * preventing two customers from both proceeding with the same
 * capacity-limited slot. A cart may only ever have one open (non-released,
 * non-converted) hold: selecting a new slot atomically releases the cart's
 * previous hold and creates the new one, only after capacity for the new
 * slot is confirmed - so a request that fails on capacity never loses the
 * customer's existing hold. Lock order: USER -> CART -> SLOT -> HOLD(s),
 * matching §13 of docs/api-contracts/checkout-v1.md. The `appointment_slots`
 * row lock serializes every concurrent hold attempt on that slot, so the
 * occupancy count read here is always up to date before the capacity
 * decision is made.
 */
class CreateAppointmentHoldAction
{
    use BuildsCartResult;

    public function __construct(private readonly CheckoutPresenter $presenter = new CheckoutPresenter) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $userUuid, string $slotUuid): array
    {
        return DB::transaction(function () use ($userUuid, $slotUuid) {
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

            $cartIdBinary = UuidBinary::toBinary($cart->id);

            try {
                $slotIdBinary = UuidBinary::toBinary($slotUuid);
            } catch (InvalidArgumentException) {
                return $this->notFound('Appointment slot not found.');
            }

            $slot = AppointmentSlot::query()
                ->join('appointment_time_windows', 'appointment_time_windows.id', '=', 'appointment_slots.time_window_id')
                ->where('appointment_slots.id', $slotIdBinary)
                ->where('appointment_slots.is_active', 1)
                ->where('appointment_time_windows.is_active', 1)
                ->lockForUpdate()
                ->first(['appointment_slots.*']);

            if ($slot === null) {
                return $this->notFound('Appointment slot not found.');
            }

            $now = now();

            if ($slot->starts_at->lessThanOrEqualTo($now)) {
                return $this->unprocessable('This appointment slot has already passed.');
            }

            $occupied = AppointmentSlotOccupancy::query($now)
                ->where('appointment_slot_id', $slotIdBinary)
                ->where('cart_id', '!=', $cartIdBinary)
                ->lockForUpdate()
                ->count();

            if ($occupied >= (int) $slot->booking_capacity) {
                return $this->unprocessable('This appointment slot is fully booked.');
            }

            // The query builder truncates DateTimeInterface bindings to whole-second
            // precision (Illuminate\Database\Connection::prepareBindings() formats them
            // with the grammar's 'Y-m-d H:i:s' date format), even though this table's
            // columns are datetime(6). Pre-formatting with microseconds here preserves
            // the precision these raw inserts/updates would otherwise silently drop.
            $timestamp = $now->format('Y-m-d H:i:s.u');

            DB::table('appointment_holds')
                ->where('cart_id', $cartIdBinary)
                ->whereNull('released_at')
                ->whereNull('converted_at')
                ->update(['released_at' => $timestamp, 'updated_at' => $timestamp]);

            $holdUuid = UuidBinary::generate();
            $ttlMinutes = (int) config('checkout.appointment_hold_ttl_minutes');

            DB::table('appointment_holds')->insert([
                'id' => UuidBinary::toBinary($holdUuid),
                'cart_id' => $cartIdBinary,
                'appointment_slot_id' => $slotIdBinary,
                'held_at' => $timestamp,
                'expires_at' => $now->copy()->addMinutes($ttlMinutes)->format('Y-m-d H:i:s.u'),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $cart->last_activity_at = $now;
            $cart->save();

            return $this->ok(201, 'Appointment held.', ['checkout' => $this->presenter->present($cart->fresh())]);
        });
    }
}
