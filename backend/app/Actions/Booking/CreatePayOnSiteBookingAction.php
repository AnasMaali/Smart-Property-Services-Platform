<?php

namespace App\Actions\Booking;

use App\Models\AppointmentSlot;
use App\Models\Cart;
use App\Models\CartLocation;
use App\Models\User;
use App\Support\Auth\PendingAccountDeletionGuard;
use App\Support\Booking\BookingItemStatuses;
use App\Support\Booking\BookingNumberGenerator;
use App\Support\Booking\BookingPresenter;
use App\Support\Booking\BookingSnapshotConverter;
use App\Support\Booking\BookingSources;
use App\Support\Booking\BookingStatuses;
use App\Support\Cart\CartStatuses;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Checkout\CheckoutPaymentPolicy;
use App\Support\Checkout\CheckoutPresenter;
use App\Support\Payment\CheckoutSnapshotBuilder;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * BLUE V1 Phase B24 - the ONE customer-facing entry point for confirming a
 * Booking without an online Stripe payment (POST /v1/bookings/pay-on-site).
 * Deliberately mirrors App\Actions\Payment\CreatePaymentAttemptAction's
 * Transaction A validation almost exactly (same lock order: USER -> CART ->
 * CART_LOCATION -> APPOINTMENT_SLOT -> APPOINTMENT_HOLD; same
 * ready_for_payment/hold-freshness/pricing checks; the SAME
 * App\Support\Payment\CheckoutSnapshotBuilder freezes the identical
 * snapshot shape) - the only real difference is what happens once
 * validation passes: no PaymentGateway is ever called, no `payment_attempts`
 * row is ever created, and the Booking is written directly inside the same
 * locked transaction (mirroring App\Actions\Booking\
 * CreateBookingFromSuccessfulPaymentAction's own `writeBooking()` via the
 * shared App\Support\Booking\BookingSnapshotConverter).
 *
 * PAY_ON_SITE is never treated as a successful payment: the Booking is
 * created at `booking_statuses.CONFIRMED` (never PAID), `payment_attempt_id`
 * stays NULL (the same "no new customer Payment" shape `booking_sources.
 * CONTRACT` already uses), and a `booking_on_site_settlements` row records
 * the amount due with nothing collected yet.
 *
 * Idempotency mirrors CreatePaymentAttemptAction's Idempotency-Key header
 * convention exactly (same UUID-format validation, same sha256-of-lowercase
 * hash), but the dedup anchor is `bookings.idempotency_key` directly (a
 * Booking, unlike a Payment, is a one-shot terminal write with nothing to
 * "resume") - a replay with the same key always returns the SAME Booking,
 * never a second one. A concurrent duplicate insert is resolved by
 * re-reading the winner, exactly like CreateBookingFromSuccessfulPaymentAction
 * ::resolveInsertRace().
 */
class CreatePayOnSiteBookingAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly CheckoutPresenter $checkoutPresenter = new CheckoutPresenter,
        private readonly CheckoutSnapshotBuilder $snapshotBuilder = new CheckoutSnapshotBuilder,
        private readonly PendingAccountDeletionGuard $deletionGuard = new PendingAccountDeletionGuard,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $userUuid, string $rawIdempotencyKey): array
    {
        if (! Str::isUuid($rawIdempotencyKey)) {
            return $this->unprocessable('The Idempotency-Key header must be a valid UUID.');
        }

        $userIdBinary = UuidBinary::toBinary($userUuid);
        $idempotencyHash = hash('sha256', strtolower($rawIdempotencyKey), true);

        $existing = DB::table('bookings')->where('idempotency_key', $idempotencyHash)->first();

        if ($existing !== null) {
            return $this->respondWithBooking($existing, alreadyExisted: true);
        }

        try {
            return DB::transaction(fn () => $this->convert($userUuid, $userIdBinary, $idempotencyHash));
        } catch (UniqueConstraintViolationException $e) {
            return $this->handleInsertRace($e, $idempotencyHash);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function convert(string $userUuid, string $userIdBinary, string $idempotencyHash): array
    {
        $now = now();

        $user = User::where('id', $userIdBinary)->lockForUpdate()->first();

        if ($user === null) {
            throw new RuntimeException("Authenticated user {$userUuid} not found.");
        }

        if ($this->deletionGuard->isPending($userIdBinary)) {
            return $this->conflict(PendingAccountDeletionGuard::REJECTION_MESSAGE);
        }

        $cart = Cart::where('customer_user_id', $userIdBinary)
            ->where('status_id', CartStatuses::id('ACTIVE'))
            ->lockForUpdate()
            ->first();

        if ($cart === null) {
            return $this->notFound('No active cart to confirm.');
        }

        $cartIdBinary = UuidBinary::toBinary($cart->id);

        $location = CartLocation::where('cart_id', $cartIdBinary)->lockForUpdate()->first();

        if ($location === null) {
            return $this->unprocessable('Checkout is not ready to confirm.');
        }

        $currentHold = DB::table('appointment_holds')
            ->where('cart_id', $cartIdBinary)
            ->whereNull('released_at')
            ->whereNull('converted_at')
            ->where('expires_at', '>', $now)
            ->orderByDesc('created_at')
            ->first(['id', 'appointment_slot_id']);

        if ($currentHold === null) {
            return $this->unprocessable('Checkout is not ready to confirm.');
        }

        $slot = AppointmentSlot::query()
            ->join('appointment_time_windows', 'appointment_time_windows.id', '=', 'appointment_slots.time_window_id')
            ->where('appointment_slots.id', $currentHold->appointment_slot_id)
            ->where('appointment_slots.is_active', 1)
            ->where('appointment_time_windows.is_active', 1)
            ->lockForUpdate()
            ->first(['appointment_slots.*']);

        if ($slot === null || $slot->starts_at->lessThanOrEqualTo($now)) {
            return $this->unprocessable('Checkout is not ready to confirm.');
        }

        $heldRow = DB::table('appointment_holds')->where('id', $currentHold->id)->lockForUpdate()->first();

        if ($heldRow === null
            || $heldRow->released_at !== null
            || $heldRow->converted_at !== null
            || Carbon::parse($heldRow->expires_at)->lessThanOrEqualTo($now)
        ) {
            return $this->unprocessable('Checkout is not ready to confirm.');
        }

        $presented = $this->checkoutPresenter->present($cart);

        if ($presented['ready_for_payment'] !== true) {
            return $this->unprocessable('Checkout is not ready to confirm.');
        }

        $serviceUuids = collect($presented['items'])->pluck('service.uuid')->all();
        $policy = CheckoutPaymentPolicy::availableMethodsFor($serviceUuids);

        if (! in_array('PAY_ON_SITE', $policy['codes'], true)) {
            return $this->unprocessable('Pay on Site is not available for this Cart - one or more Services require online prepayment.', [
                'payment_method' => ['PAY_ON_SITE is not an available payment method for this Cart.'],
            ]);
        }

        $snapshot = $this->snapshotBuilder->build($location, $slot, $presented);

        $items = BookingSnapshotConverter::buildItems($snapshot['items']);

        if ($items === null) {
            return $this->unprocessable('Checkout is not ready to confirm.');
        }

        $resolvedLocation = BookingSnapshotConverter::resolveLocation($snapshot['location']);

        if ($resolvedLocation === null) {
            return $this->unprocessable('Checkout is not ready to confirm.');
        }

        $timestamp = $now->format('Y-m-d H:i:s.u');
        $bookingIdBinary = UuidBinary::toBinary(UuidBinary::generate());
        $confirmedStatusId = BookingStatuses::id('CONFIRMED');
        $pendingItemStatusId = BookingItemStatuses::id('PENDING_ASSIGNMENT');

        DB::table('bookings')->insert([
            'id' => $bookingIdBinary,
            'booking_number' => BookingNumberGenerator::generate(),
            'cart_id' => $cartIdBinary,
            'payment_attempt_id' => null,
            'booking_source_id' => BookingSources::id('PAY_ON_SITE'),
            'payment_method_code' => 'PAY_ON_SITE',
            'idempotency_key' => $idempotencyHash,
            'appointment_slot_id' => $currentHold->appointment_slot_id,
            'status_id' => $confirmedStatusId,
            'status_changed_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('booking_status_history')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'booking_id' => $bookingIdBinary,
            'from_status_id' => null,
            'to_status_id' => $confirmedStatusId,
            'changed_by_user_id' => null,
            'reason' => null,
            'changed_at' => $timestamp,
        ]);

        DB::table('booking_locations')->insert([
            'booking_id' => $bookingIdBinary,
            'property_type_id' => $resolvedLocation['property_type_id'],
            'area_id' => $resolvedLocation['area_id'],
            'property_type_name_snapshot' => $resolvedLocation['property_type_name_snapshot'],
            'country_name_snapshot' => $resolvedLocation['country_name_snapshot'],
            'city_name_snapshot' => $resolvedLocation['city_name_snapshot'],
            'area_name_snapshot' => $resolvedLocation['area_name_snapshot'],
            'other_property_type_name' => $resolvedLocation['other_property_type_name'],
            'street_name' => $resolvedLocation['street_name'],
            'address_line' => $resolvedLocation['address_line'],
            'building_name_or_number' => $resolvedLocation['building_name_or_number'],
            'floor_number' => $resolvedLocation['floor_number'],
            'unit_number' => $resolvedLocation['unit_number'],
            'nearby_landmark' => $resolvedLocation['nearby_landmark'],
            'additional_location_notes' => $resolvedLocation['additional_location_notes'],
            'visit_contact_phone' => $resolvedLocation['visit_contact_phone'],
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $amountDue = '0.000000';

        foreach ($items as $item) {
            $amountDue = bcadd($amountDue, $item['line_total_amount'], 6);

            DB::table('booking_items')->insert([
                'id' => UuidBinary::toBinary(UuidBinary::generate()),
                'booking_id' => $bookingIdBinary,
                'source_cart_item_id' => $item['source_cart_item_id'],
                'service_id' => $item['service_id'],
                'pricing_scheme_version_id' => $item['pricing_scheme_version_id'],
                'status_id' => $pendingItemStatusId,
                'service_code_snapshot' => $item['service_code_snapshot'],
                'service_name_snapshot' => $item['service_name_snapshot'],
                'quantity' => $item['quantity'],
                'pricing_status_snapshot' => $item['pricing_status_snapshot'],
                'base_amount_snapshot' => $item['base_amount_snapshot'],
                'pricing_breakdown' => $item['pricing_breakdown'],
                'unit_total_amount' => $item['unit_total_amount'],
                'line_total_amount' => $item['line_total_amount'],
                'display_order' => $item['display_order'],
                'status_changed_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        if (bccomp($amountDue, (string) $presented['total'], 6) !== 0) {
            // Never reachable under correct CheckoutPresenter/PricingEngine
            // behavior - withheld defensively rather than silently trusting
            // a mismatched total, exactly like CreateBookingFromSuccessfulPaymentAction's
            // own AMOUNT_MISMATCH guard.
            throw new RuntimeException('Pay-on-Site booking amount_due did not match the presented Checkout total.');
        }

        DB::table('booking_on_site_settlements')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'booking_id' => $bookingIdBinary,
            'amount_due' => $amountDue,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('carts')->where('id', $cartIdBinary)->update([
            'status_id' => CartStatuses::id('CONVERTED'),
            'status_changed_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('appointment_holds')->where('id', $currentHold->id)->whereNull('released_at')->whereNull('converted_at')->update([
            'converted_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $fresh = DB::table('bookings')->where('id', $bookingIdBinary)->first();

        return $this->respondWithBooking($fresh, alreadyExisted: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function respondWithBooking(object $booking, bool $alreadyExisted): array
    {
        $withCurrency = DB::table('bookings')
            ->join('carts', 'carts.id', '=', 'bookings.cart_id')
            ->where('bookings.id', $booking->id)
            ->first(['bookings.*', 'carts.currency_id as cart_currency_id']);

        return $this->ok(
            $alreadyExisted ? 200 : 201,
            $alreadyExisted ? 'Booking already confirmed.' : 'Booking confirmed - payment due on-site.',
            ['booking' => BookingPresenter::present($withCurrency)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function handleInsertRace(UniqueConstraintViolationException $e, string $idempotencyHash): array
    {
        $existing = DB::table('bookings')->where('idempotency_key', $idempotencyHash)->first();

        if ($existing !== null) {
            return $this->respondWithBooking($existing, alreadyExisted: true);
        }

        if (str_contains($e->getMessage(), 'uq_bookings_cart')) {
            return $this->conflict('A Booking already exists for this Cart.');
        }

        throw $e;
    }
}
