<?php

namespace App\Actions\Admin\Booking;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminRepairQuotePresenter;
use App\Support\Booking\BookingItemRepairQuoteStatuses;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BLUE V1 Phase B25 - edits a DRAFT repair quote's `quoted_amount` (PATCH
 * /v1/admin/repair-quotes/{quote}). `credit_amount` never changes here - it
 * remains the historical fact frozen at creation time (BLUE V1 catalog spec
 * Phase B25 section 5) - only `balance_due_amount` is recomputed. Once a
 * quote is SENT/ACCEPTED/etc. its amounts are immutable (see
 * App\Actions\Admin\Booking\AdminSendRepairQuoteAction's docblock) - this
 * Action rejects anything but a DRAFT outright rather than silently no-op
 * -ing, so an Admin never mistakes a rejected edit for an applied one.
 */
final class AdminUpdateDraftRepairQuoteAction
{
    use BuildsCartResult;

    public function handle(Request $request, User $actor, string $quoteUuid, string $quotedAmount): array
    {
        try {
            $quoteIdBinary = UuidBinary::toBinary($quoteUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Repair quote not found.');
        }

        return DB::transaction(function () use ($request, $actor, $quoteUuid, $quoteIdBinary, $quotedAmount): array {
            $quote = DB::table('booking_item_repair_quotes')->where('id', $quoteIdBinary)->lockForUpdate()->first();

            if ($quote === null) {
                return $this->notFound('Repair quote not found.');
            }

            if ((int) $quote->status_id !== BookingItemRepairQuoteStatuses::id('DRAFT')) {
                return $this->unprocessable('Only a DRAFT repair quote may be edited - send a revision instead.');
            }

            if (bccomp($quotedAmount, $quote->credit_amount, 6) < 0) {
                return $this->unprocessable(
                    "The quoted amount ({$quotedAmount}) is below the eligible inspection credit ({$quote->credit_amount}).",
                    ['quoted_amount' => ["quoted_amount must be at least the eligible inspection credit ({$quote->credit_amount})."]],
                );
            }

            $balanceDue = bcsub($quotedAmount, (string) $quote->credit_amount, 6);
            $timestamp = now()->format('Y-m-d H:i:s.u');

            DB::table('booking_item_repair_quotes')->where('id', $quoteIdBinary)->update([
                'quoted_amount' => $quotedAmount,
                'balance_due_amount' => $balanceDue,
                'updated_at' => $timestamp,
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'REPAIR_QUOTE_DRAFT_EDITED',
                'BOOKING_ITEM_REPAIR_QUOTE',
                $quoteUuid,
                ['quoted_amount' => $quotedAmount, 'balance_due_amount' => $balanceDue],
                ['quoted_amount' => (string) $quote->quoted_amount, 'balance_due_amount' => (string) $quote->balance_due_amount],
            );

            $fresh = DB::table('booking_item_repair_quotes')->where('id', $quoteIdBinary)->first();

            return $this->ok(200, 'Repair quote draft updated.', ['quote' => AdminRepairQuotePresenter::present($fresh)]);
        });
    }
}
