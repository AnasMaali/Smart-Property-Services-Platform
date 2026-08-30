<?php

namespace App\Actions\Admin\Booking;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminRepairQuotePresenter;
use App\Support\Booking\BookingItemRepairQuoteStatuses;
use App\Support\Booking\InspectionCreditCalculator;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BLUE V1 Phase B25 - creates a DRAFT post-inspection repair quote for one
 * Booking Item (POST /v1/admin/booking-items/{bookingItem}/repair-quotes).
 * Every eligibility check is data-driven, never a hardcoded Service code:
 *
 *   1. The Booking Item's Service has `inspection_quote_credit_enabled`.
 *   2. The Booking Item itself is COMPLETED (the inspection actually
 *      happened - see App\Support\Booking\BookingItemStatusMachine) and not
 *      cancelled.
 *   3. The Booking Item's Booking has a SUCCESSFUL online PaymentAttempt to
 *      credit from - see App\Support\Booking\InspectionCreditCalculator for
 *      why this is always safe even in a multi-item Booking.
 *   4. No other quote for this exact Booking Item is currently active
 *      (DRAFT/SENT/ACCEPTED) - both checked explicitly here (for a clean
 *      409) AND enforced unconditionally by the database's own
 *      `uq_biq_active_booking_item` generated-column UNIQUE constraint
 *      (BLUE V1 catalog spec Phase B25 section 12 - concurrency safety),
 *      so a race between two Admins can never create two active quotes.
 *
 * `quoted_amount` is the ONLY financial input this Action ever trusts from
 * the request - `credit_amount`/`balance_due_amount` are always computed
 * here, server-side, from the historical PaymentAttempt fact above, never
 * from client input (BLUE V1 catalog spec Phase B25 section 10/45). A
 * `quoted_amount` below the eligible credit is rejected outright (section
 * 40) - BLUE V1 has no defined policy for automatically refunding the
 * excess, so this Action never invents one.
 */
final class AdminCreateRepairQuoteAction
{
    use BuildsCartResult;

    public function handle(Request $request, User $actor, string $bookingItemUuid, string $quotedAmount): array
    {
        try {
            $bookingItemIdBinary = UuidBinary::toBinary($bookingItemUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Booking Item not found.');
        }

        if (bccomp($quotedAmount, '0', 6) < 0) {
            return $this->unprocessable('The given data was invalid.', ['quoted_amount' => ['quoted_amount must not be negative.']]);
        }

        try {
            return DB::transaction(fn () => $this->create($request, $actor, $bookingItemIdBinary, $quotedAmount));
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'active_booking_item')) {
                return $this->conflict('An active repair quote already exists for this Booking Item.');
            }

            throw $e;
        }
    }

    private function create(Request $request, User $actor, string $bookingItemIdBinary, string $quotedAmount): array
    {
        $bookingItem = DB::table('booking_items')->where('id', $bookingItemIdBinary)->lockForUpdate()->first();

        if ($bookingItem === null) {
            return $this->notFound('Booking Item not found.');
        }

        $booking = DB::table('bookings')->where('id', $bookingItem->booking_id)->first();

        if ($booking === null) {
            return $this->notFound('Booking not found.');
        }

        $serviceInspectionEnabled = (bool) DB::table('services')->where('id', $bookingItem->service_id)->value('inspection_quote_credit_enabled');

        if (! $serviceInspectionEnabled) {
            return $this->unprocessable('This Service is not enabled for the inspection quote-credit workflow.');
        }

        $hasActiveQuote = DB::table('booking_item_repair_quotes')
            ->where('booking_item_id', $bookingItemIdBinary)
            ->whereNull('closed_at')
            ->lockForUpdate()
            ->exists();

        if ($hasActiveQuote) {
            return $this->conflict('An active repair quote already exists for this Booking Item.');
        }

        $eligibility = InspectionCreditCalculator::eligibilityFor($bookingItem, $booking);

        if (! $eligibility['eligible']) {
            return $this->unprocessable($eligibility['reason']);
        }

        $creditAmount = $eligibility['amount'];

        if (bccomp($quotedAmount, $creditAmount, 6) < 0) {
            return $this->unprocessable(
                "The quoted amount ({$quotedAmount}) is below the eligible inspection credit ({$creditAmount}). BLUE V1 has no automated refund policy for excess inspection credit - reject or revise the quote instead.",
                ['quoted_amount' => ["quoted_amount must be at least the eligible inspection credit ({$creditAmount})."]],
            );
        }

        $balanceDue = bcsub($quotedAmount, $creditAmount, 6);

        $now = now();
        $timestamp = $now->format('Y-m-d H:i:s.u');
        $quoteIdBinary = UuidBinary::toBinary(UuidBinary::generate());

        DB::table('booking_item_repair_quotes')->insert([
            'id' => $quoteIdBinary,
            'booking_id' => $bookingItem->booking_id,
            'booking_item_id' => $bookingItemIdBinary,
            'status_id' => BookingItemRepairQuoteStatuses::id('DRAFT'),
            'currency_id' => $this->currencyIdFor($booking),
            'quoted_amount' => $quotedAmount,
            'credit_amount' => $creditAmount,
            'balance_due_amount' => $balanceDue,
            'created_by_admin_user_id' => UuidBinary::toBinary($actor->id),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('repair_quote_credits')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'quote_id' => $quoteIdBinary,
            'source_booking_id' => $eligibility['booking_id'],
            'source_booking_item_id' => $bookingItemIdBinary,
            'source_payment_attempt_id' => $eligibility['payment_attempt_id'],
            'amount' => $creditAmount,
            'created_at' => $timestamp,
        ]);

        AdminAuditLogger::record(
            $request,
            $actor,
            'REPAIR_QUOTE_CREATED',
            'BOOKING_ITEM_REPAIR_QUOTE',
            UuidBinary::toString($quoteIdBinary),
            ['quoted_amount' => $quotedAmount, 'credit_amount' => $creditAmount, 'balance_due_amount' => $balanceDue],
        );

        $quote = DB::table('booking_item_repair_quotes')->where('id', $quoteIdBinary)->first();

        return $this->ok(201, 'Repair quote draft created.', ['quote' => AdminRepairQuotePresenter::present($quote)]);
    }

    private function currencyIdFor(object $booking): int
    {
        return (int) DB::table('carts')->where('id', $booking->cart_id)->value('currency_id');
    }
}
