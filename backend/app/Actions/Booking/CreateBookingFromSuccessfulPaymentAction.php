<?php

namespace App\Actions\Booking;

use App\Support\Booking\BookingConversionOutcome;
use App\Support\Booking\BookingConversionResult;
use App\Support\Booking\BookingItemStatuses;
use App\Support\Booking\BookingNumberGenerator;
use App\Support\Booking\BookingSnapshotConverter;
use App\Support\Booking\BookingSources;
use App\Support\Booking\BookingStatuses;
use App\Support\Cart\CartStatuses;
use App\Support\Payment\CanonicalJson;
use App\Support\Payment\PaymentStatuses;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The one canonical, idempotent entry point for converting a trusted
 * SUCCESSFUL payment_attempt into a Booking (BLUE V1 Phase 7A). Never
 * called with data from Flutter - the caller always supplies a
 * payment_attempts UUID already resolved server-side, either by
 * ProcessPaymentWebhookAction right after a provider-confirmed success
 * commits, or by the bookings:convert-successful-payments recovery command.
 *
 * Exactly-once guarantee: an application-level existence check
 * (bookings.payment_attempt_id) plus the schema's own
 * UNIQUE(payment_attempt_id) / UNIQUE(cart_id) constraints on `bookings`
 * together make calling handle() any number of times - fresh call, webhook
 * retry, concurrent recovery-command run - converge on exactly one Booking.
 * A unique-key race (two callers reaching the insert at once) is resolved
 * by re-reading and returning the winner's Booking, never by letting a SQL
 * exception escape.
 *
 * Lock order: PAYMENT_ATTEMPT -> CART -> APPOINTMENT_HOLD. This is a new
 * root distinct from every Phase 1-6B customer-request flow (USER -> CART
 * -> ...), which is safe without a cycle risk because:
 *   - Every Phase 1-6B write path that locks a `carts` row first resolves
 *     it as the customer's ACTIVE cart (Cart::where('status_id', ACTIVE)).
 *     By the time this action ever runs, the payment's cart is CHECKOUT
 *     (or already CONVERTED), so those paths never select - and therefore
 *     never lock - the same cart row this action locks. The two lock
 *     orders are proven disjoint by that status invariant, not merely
 *     unlikely to collide.
 *   - ProcessPaymentWebhookAction (the only other path that locks a
 *     payment_attempts row directly) always locks that row first too, and
 *     never locks CART/APPOINTMENT_HOLD itself on the SUCCEEDED path - so
 *     two overlapping calls for the *same* attempt simply queue on the
 *     PAYMENT_ATTEMPT lock in the same order, never deadlock.
 * appointment_slots is deliberately never locked here: conversion makes no
 * capacity/availability decision (that already happened when the hold was
 * created) and every display fact this action needs about the slot already
 * lives in the frozen checkout_snapshot.
 *
 * No external/network call is ever made inside this transaction (no Stripe
 * call, no PricingEngine re-run) - every monetary and descriptive value is
 * read from the already-frozen, already-hashed checkout_snapshot.
 */
class CreateBookingFromSuccessfulPaymentAction
{
    public function handle(string $paymentAttemptUuid): BookingConversionResult
    {
        $paymentAttemptIdBinary = UuidBinary::toBinary($paymentAttemptUuid);

        try {
            return DB::transaction(fn () => $this->convert($paymentAttemptIdBinary));
        } catch (UniqueConstraintViolationException) {
            return $this->resolveInsertRace($paymentAttemptIdBinary);
        }
    }

    private function convert(string $paymentAttemptIdBinary): BookingConversionResult
    {
        $attempt = DB::table('payment_attempts')->where('id', $paymentAttemptIdBinary)->lockForUpdate()->first();

        if ($attempt === null) {
            return new BookingConversionResult(BookingConversionOutcome::NOT_FOUND);
        }

        $existingBooking = DB::table('bookings')->where('payment_attempt_id', $paymentAttemptIdBinary)->first(['id']);

        if ($existingBooking !== null) {
            return new BookingConversionResult(BookingConversionOutcome::ALREADY_EXISTS, UuidBinary::toString($existingBooking->id));
        }

        if ((int) $attempt->status_id !== PaymentStatuses::id('SUCCESSFUL')) {
            return new BookingConversionResult(BookingConversionOutcome::NOT_ELIGIBLE, reason: 'PAYMENT_NOT_SUCCESSFUL');
        }

        if ((int) $attempt->requires_reconciliation === 1) {
            return new BookingConversionResult(BookingConversionOutcome::NOT_ELIGIBLE, reason: 'REQUIRES_RECONCILIATION');
        }

        $now = now();

        $recomputedHash = CanonicalJson::sha256(CanonicalJson::encode(json_decode((string) $attempt->checkout_snapshot, true)));

        if (! hash_equals((string) $attempt->checkout_snapshot_hash, $recomputedHash)) {
            $this->flagReconciliation($attempt->id, $now, 'SNAPSHOT_INTEGRITY_FAILURE');

            return new BookingConversionResult(BookingConversionOutcome::RECONCILIATION_REQUIRED, reason: 'SNAPSHOT_INTEGRITY_FAILURE');
        }

        $snapshot = json_decode((string) $attempt->checkout_snapshot, true);

        $cart = DB::table('carts')->where('id', $attempt->cart_id)->lockForUpdate()->first();

        if ($cart === null || (int) $cart->status_id !== CartStatuses::id('CHECKOUT')) {
            return new BookingConversionResult(BookingConversionOutcome::BLOCKED, reason: 'CART_NOT_IN_CHECKOUT_STATE');
        }

        $hold = DB::table('appointment_holds')->where('id', $attempt->appointment_hold_id)->lockForUpdate()->first();

        if ($hold === null || $hold->cart_id !== $attempt->cart_id) {
            $this->flagReconciliation($attempt->id, $now, 'HOLD_CART_MISMATCH');

            return new BookingConversionResult(BookingConversionOutcome::RECONCILIATION_REQUIRED, reason: 'HOLD_CART_MISMATCH');
        }

        if ($hold->converted_at !== null) {
            // No Booking exists for this attempt (checked above) yet the
            // hold is already converted - an unreachable state under the
            // current architecture (a hold converts only alongside the one
            // Booking created in this same transaction). Withheld, not
            // guessed at.
            return new BookingConversionResult(BookingConversionOutcome::BLOCKED, reason: 'HOLD_ALREADY_CONVERTED');
        }

        if ($hold->released_at !== null) {
            $this->flagReconciliation($attempt->id, $now, 'HOLD_RELEASED');

            return new BookingConversionResult(BookingConversionOutcome::RECONCILIATION_REQUIRED, reason: 'HOLD_RELEASED');
        }

        if (Carbon::parse($hold->expires_at)->lessThanOrEqualTo($now)) {
            $this->flagReconciliation($attempt->id, $now, 'HOLD_EXPIRED');

            return new BookingConversionResult(BookingConversionOutcome::RECONCILIATION_REQUIRED, reason: 'HOLD_EXPIRED');
        }

        $items = $this->buildBookingItems($snapshot);

        if ($items === null) {
            $this->flagReconciliation($attempt->id, $now, 'SNAPSHOT_INTEGRITY_FAILURE');

            return new BookingConversionResult(BookingConversionOutcome::RECONCILIATION_REQUIRED, reason: 'SNAPSHOT_INTEGRITY_FAILURE');
        }

        $itemsTotal = '0.000000';
        foreach ($items as $item) {
            $itemsTotal = bcadd($itemsTotal, $item['line_total_amount'], 6);
        }

        $confirmedAmount = (string) ($attempt->confirmed_amount ?? $attempt->requested_amount);

        if (bccomp($itemsTotal, $confirmedAmount, 6) !== 0 || bccomp($itemsTotal, (string) $snapshot['requested_total'], 6) !== 0) {
            $this->flagReconciliation($attempt->id, $now, 'AMOUNT_MISMATCH');

            return new BookingConversionResult(BookingConversionOutcome::RECONCILIATION_REQUIRED, reason: 'AMOUNT_MISMATCH');
        }

        $location = $this->resolveLocationSnapshot($snapshot['location'] ?? null);

        if ($location === null) {
            $this->flagReconciliation($attempt->id, $now, 'SNAPSHOT_INTEGRITY_FAILURE');

            return new BookingConversionResult(BookingConversionOutcome::RECONCILIATION_REQUIRED, reason: 'SNAPSHOT_INTEGRITY_FAILURE');
        }

        $bookingUuid = $this->writeBooking($attempt, $hold, $now, $items, $location);

        return new BookingConversionResult(BookingConversionOutcome::CREATED, $bookingUuid);
    }

    /**
     * @return array<int, array<string, mixed>>|null null means the frozen
     *                                               snapshot failed an internal consistency check that must never
     *                                               reach the database (e.g. a QUOTE_REQUIRED item, or line_total !=
     *                                               unit_total * quantity) - the caller flags reconciliation instead.
     */
    private function buildBookingItems(array $snapshot): ?array
    {
        return BookingSnapshotConverter::buildItems($snapshot['items'] ?? []);
    }

    /**
     * Resolves the historical location snapshot from reference/lookup data
     * (property_types, areas, cities, countries) keyed by the immutable
     * ids the frozen checkout_snapshot already carries - the same
     * "server-resolved code from a stable reference id" pattern
     * CheckoutSnapshotBuilder itself already uses for resolved_context.
     * Never reads the customer's current profile/cart_location - the
     * snapshot's own free-text fields (street, building, phone, ...) are
     * copied through verbatim.
     *
     * @return array<string, mixed>|null
     */
    private function resolveLocationSnapshot(?array $snapshotLocation): ?array
    {
        return BookingSnapshotConverter::resolveLocation($snapshotLocation);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $location
     */
    private function writeBooking(object $attempt, object $hold, Carbon $now, array $items, array $location): string
    {
        $timestamp = $now->format('Y-m-d H:i:s.u');
        $bookingIdBinary = UuidBinary::toBinary(UuidBinary::generate());
        $paidStatusId = BookingStatuses::id('PAID');
        $pendingItemStatusId = BookingItemStatuses::id('PENDING_ASSIGNMENT');

        DB::table('bookings')->insert([
            'id' => $bookingIdBinary,
            'booking_number' => BookingNumberGenerator::generate(),
            'cart_id' => $attempt->cart_id,
            'payment_attempt_id' => $attempt->id,
            'booking_source_id' => BookingSources::id('STANDARD'),
            'payment_method_code' => $this->paymentMethodCodeFor($attempt->payment_method_type),
            'appointment_slot_id' => $hold->appointment_slot_id,
            'status_id' => $paidStatusId,
            'status_changed_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('booking_status_history')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'booking_id' => $bookingIdBinary,
            'from_status_id' => null,
            'to_status_id' => $paidStatusId,
            'changed_by_user_id' => null,
            'reason' => null,
            'changed_at' => $timestamp,
        ]);

        DB::table('booking_locations')->insert([
            'booking_id' => $bookingIdBinary,
            'property_type_id' => $location['property_type_id'],
            'area_id' => $location['area_id'],
            'property_type_name_snapshot' => $location['property_type_name_snapshot'],
            'country_name_snapshot' => $location['country_name_snapshot'],
            'city_name_snapshot' => $location['city_name_snapshot'],
            'area_name_snapshot' => $location['area_name_snapshot'],
            'other_property_type_name' => $location['other_property_type_name'],
            'street_name' => $location['street_name'],
            'address_line' => $location['address_line'],
            'building_name_or_number' => $location['building_name_or_number'],
            'floor_number' => $location['floor_number'],
            'unit_number' => $location['unit_number'],
            'nearby_landmark' => $location['nearby_landmark'],
            'additional_location_notes' => $location['additional_location_notes'],
            'visit_contact_phone' => $location['visit_contact_phone'],
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        foreach ($items as $item) {
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

        DB::table('carts')->where('id', $attempt->cart_id)->where('status_id', CartStatuses::id('CHECKOUT'))->update([
            'status_id' => CartStatuses::id('CONVERTED'),
            'status_changed_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('appointment_holds')->where('id', $hold->id)->whereNull('released_at')->whereNull('converted_at')->update([
            'converted_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return UuidBinary::toString($bookingIdBinary);
    }

    /**
     * BLUE V1 Phase B24 - maps the provider-verified, lowercase Stripe
     * `payment_method_type` (App\Support\Payment\Gateway\
     * StripePaymentGateway::normalizePaymentMethodType() - a wallet type
     * such as "apple_pay" when Stripe expands it, otherwise the declared
     * `payment_method_types[0]`, e.g. "card") onto BLUE's own canonical
     * `bookings.payment_method_code` vocabulary. Never a value Flutter
     * declares. Every non-Apple-Pay online method (plain "card", or an
     * unrecognized/未-known value, or null when Stripe has not reported one
     * yet) safely falls back to CARD - the only two online rails this
     * phase's business requirement recognizes are CARD and APPLE_PAY, and
     * this Action's caller is by definition the successful-Stripe-payment
     * path, never Pay-on-Site.
     */
    private function paymentMethodCodeFor(?string $stripePaymentMethodType): string
    {
        return $stripePaymentMethodType === 'apple_pay' ? 'APPLE_PAY' : 'CARD';
    }

    private function flagReconciliation(string $paymentAttemptIdBinary, Carbon $at, string $reasonCode): void
    {
        DB::table('payment_attempts')->where('id', $paymentAttemptIdBinary)->update([
            'requires_reconciliation' => 1,
            'reconciliation_reason_code' => $reasonCode,
            'updated_at' => $at->format('Y-m-d H:i:s.u'),
        ]);
    }

    private function resolveInsertRace(string $paymentAttemptIdBinary): BookingConversionResult
    {
        $existing = DB::table('bookings')->where('payment_attempt_id', $paymentAttemptIdBinary)->first(['id']);

        if ($existing !== null) {
            return new BookingConversionResult(BookingConversionOutcome::ALREADY_EXISTS, UuidBinary::toString($existing->id));
        }

        // A UNIQUE(payment_attempt_id)/UNIQUE(cart_id) violation on
        // `bookings` with no resolvable row for either key is not a
        // legitimate exactly-once race (that always leaves a winner
        // findable by payment_attempt_id) - it indicates a genuine,
        // unexpected constraint conflict that must not be silently
        // swallowed.
        throw new RuntimeException('Booking insert violated a unique constraint but no resolvable Booking row was found.');
    }
}
