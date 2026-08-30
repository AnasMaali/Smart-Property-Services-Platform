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
 * BLUE V1 Phase B25 - withdraws a DRAFT repair quote that was never sent to
 * the customer (POST /v1/admin/repair-quotes/{quote}/cancel). Scoped to
 * DRAFT only in V1 - a SENT/ACCEPTED quote is a historical business
 * document a customer may already be acting on, so correcting or
 * withdrawing one always goes through App\Actions\Admin\Booking\
 * AdminCreateRepairQuoteRevisionAction instead, never this Action.
 */
final class AdminCancelDraftRepairQuoteAction
{
    use BuildsCartResult;

    public function handle(Request $request, User $actor, string $quoteUuid): array
    {
        try {
            $quoteIdBinary = UuidBinary::toBinary($quoteUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Repair quote not found.');
        }

        return DB::transaction(function () use ($request, $actor, $quoteIdBinary, $quoteUuid): array {
            $quote = DB::table('booking_item_repair_quotes')->where('id', $quoteIdBinary)->lockForUpdate()->first();

            if ($quote === null) {
                return $this->notFound('Repair quote not found.');
            }

            if ((int) $quote->status_id !== BookingItemRepairQuoteStatuses::id('DRAFT')) {
                return $this->unprocessable('Only a DRAFT repair quote may be cancelled directly - use a revision for a SENT/ACCEPTED quote.');
            }

            $timestamp = now()->format('Y-m-d H:i:s.u');

            DB::table('booking_item_repair_quotes')->where('id', $quoteIdBinary)->update([
                'status_id' => BookingItemRepairQuoteStatuses::id('CANCELLED'),
                'cancelled_at' => $timestamp,
                'closed_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            AdminAuditLogger::record($request, $actor, 'REPAIR_QUOTE_DRAFT_CANCELLED', 'BOOKING_ITEM_REPAIR_QUOTE', $quoteUuid, []);

            $fresh = DB::table('booking_item_repair_quotes')->where('id', $quoteIdBinary)->first();

            return $this->ok(200, 'Repair quote draft cancelled.', ['quote' => AdminRepairQuotePresenter::present($fresh)]);
        });
    }
}
