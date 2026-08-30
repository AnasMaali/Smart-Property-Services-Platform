<?php

namespace App\Actions\Admin\Booking;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminRepairQuotePresenter;
use App\Support\Booking\BookingItemRepairQuoteStatuses;
use App\Support\Booking\RepairQuoteFundingStatus;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BLUE V1 Phase B25 - corrects a SENT/ACCEPTED repair quote's amount
 * without ever mutating the historical row (POST
 * /v1/admin/repair-quotes/{quote}/revise). Within ONE transaction: the old
 * quote is closed (`closed_at` set, status CANCELLED - freeing its
 * `uq_biq_active_booking_item` marker) and a brand-new DRAFT quote is
 * inserted with `supersedes_quote_id` pointing back at it. Because both
 * happen in the same transaction, no other Admin can ever observe a moment
 * with two simultaneously-active quotes for the same Booking Item, and the
 * database's own UNIQUE constraint would reject it even if they tried
 * (BLUE V1 catalog spec Phase B25 section 12).
 *
 * `credit_amount` is copied VERBATIM from the quote being revised, never
 * recomputed - the historical inspection Booking Item/PaymentAttempt facts
 * it was derived from have not changed, and copying (rather than
 * re-running App\Support\Booking\InspectionCreditCalculator a second time)
 * guarantees the exact same credit persists across a revision with no
 * possibility of drift. This is the ONE canonical revision-credit rule:
 * never 150 + 150 = 300 - always exactly one historical credit amount,
 * carried forward untouched (section 39).
 *
 * A quote that is already fully funded (see App\Support\Booking\
 * RepairQuoteFundingStatus) is a completed financial transaction and may
 * never be revised - only a SENT quote, or an ACCEPTED quote still awaiting
 * (or mid-) balance payment, is eligible.
 */
final class AdminCreateRepairQuoteRevisionAction
{
    use BuildsCartResult;

    public function handle(Request $request, User $actor, string $quoteUuid, string $newQuotedAmount): array
    {
        try {
            $quoteIdBinary = UuidBinary::toBinary($quoteUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Repair quote not found.');
        }

        if (bccomp($newQuotedAmount, '0', 6) < 0) {
            return $this->unprocessable('The given data was invalid.', ['quoted_amount' => ['quoted_amount must not be negative.']]);
        }

        try {
            return DB::transaction(fn () => $this->revise($request, $actor, $quoteIdBinary, $newQuotedAmount));
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'active_booking_item')) {
                return $this->conflict('An active repair quote already exists for this Booking Item.');
            }

            throw $e;
        }
    }

    private function revise(Request $request, User $actor, string $quoteIdBinary, string $newQuotedAmount): array
    {
        $old = DB::table('booking_item_repair_quotes')->where('id', $quoteIdBinary)->lockForUpdate()->first();

        if ($old === null) {
            return $this->notFound('Repair quote not found.');
        }

        $statusCode = BookingItemRepairQuoteStatuses::code((int) $old->status_id);

        if (! in_array($statusCode, ['SENT', 'ACCEPTED'], true) || $old->closed_at !== null) {
            return $this->unprocessable('Only a SENT or ACCEPTED (not yet fully funded) repair quote may be revised.');
        }

        if (RepairQuoteFundingStatus::for($old) === RepairQuoteFundingStatus::FULLY_FUNDED) {
            return $this->unprocessable('This repair quote is already fully funded and may no longer be revised.');
        }

        $creditAmount = (string) $old->credit_amount;

        if (bccomp($newQuotedAmount, $creditAmount, 6) < 0) {
            return $this->unprocessable(
                "The quoted amount ({$newQuotedAmount}) is below the eligible inspection credit ({$creditAmount}).",
                ['quoted_amount' => ["quoted_amount must be at least the eligible inspection credit ({$creditAmount})."]],
            );
        }

        $balanceDue = bcsub($newQuotedAmount, $creditAmount, 6);
        $now = now();
        $timestamp = $now->format('Y-m-d H:i:s.u');

        DB::table('booking_item_repair_quotes')->where('id', $quoteIdBinary)->update([
            'status_id' => BookingItemRepairQuoteStatuses::id('CANCELLED'),
            'cancelled_at' => $timestamp,
            'closed_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $newQuoteIdBinary = UuidBinary::toBinary(UuidBinary::generate());

        DB::table('booking_item_repair_quotes')->insert([
            'id' => $newQuoteIdBinary,
            'booking_id' => $old->booking_id,
            'booking_item_id' => $old->booking_item_id,
            'status_id' => BookingItemRepairQuoteStatuses::id('DRAFT'),
            'currency_id' => $old->currency_id,
            'quoted_amount' => $newQuotedAmount,
            'credit_amount' => $creditAmount,
            'balance_due_amount' => $balanceDue,
            'supersedes_quote_id' => $quoteIdBinary,
            'created_by_admin_user_id' => UuidBinary::toBinary($actor->id),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $oldCredit = DB::table('repair_quote_credits')->where('quote_id', $quoteIdBinary)->first();

        DB::table('repair_quote_credits')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'quote_id' => $newQuoteIdBinary,
            'source_booking_id' => $oldCredit->source_booking_id,
            'source_booking_item_id' => $oldCredit->source_booking_item_id,
            'source_payment_attempt_id' => $oldCredit->source_payment_attempt_id,
            'amount' => $creditAmount,
            'created_at' => $timestamp,
        ]);

        AdminAuditLogger::record(
            $request,
            $actor,
            'REPAIR_QUOTE_REVISED',
            'BOOKING_ITEM_REPAIR_QUOTE',
            UuidBinary::toString($newQuoteIdBinary),
            ['quoted_amount' => $newQuotedAmount, 'balance_due_amount' => $balanceDue, 'supersedes_quote_uuid' => UuidBinary::toString($quoteIdBinary)],
            ['quoted_amount' => (string) $old->quoted_amount],
        );

        $fresh = DB::table('booking_item_repair_quotes')->where('id', $newQuoteIdBinary)->first();

        return $this->ok(201, 'Repair quote revision created.', ['quote' => AdminRepairQuotePresenter::present($fresh)]);
    }
}
