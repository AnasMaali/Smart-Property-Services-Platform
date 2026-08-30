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
 * BLUE V1 Phase B25 - publishes a DRAFT repair quote to the customer (POST
 * /v1/admin/repair-quotes/{quote}/send). This is the ONE moment
 * `quoted_amount`/`credit_amount`/`balance_due_amount` become immutable
 * (BLUE V1 catalog spec Phase B25 section 9) - no Action after this point
 * ever updates those three columns on this row again; a correction always
 * creates a new quote via App\Actions\Admin\Booking\
 * AdminCreateRepairQuoteRevisionAction instead.
 */
final class AdminSendRepairQuoteAction
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
                return $this->unprocessable('Only a DRAFT repair quote may be sent.');
            }

            $timestamp = now()->format('Y-m-d H:i:s.u');

            DB::table('booking_item_repair_quotes')->where('id', $quoteIdBinary)->update([
                'status_id' => BookingItemRepairQuoteStatuses::id('SENT'),
                'sent_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            AdminAuditLogger::record($request, $actor, 'REPAIR_QUOTE_SENT', 'BOOKING_ITEM_REPAIR_QUOTE', $quoteUuid, ['quoted_amount' => (string) $quote->quoted_amount]);

            $fresh = DB::table('booking_item_repair_quotes')->where('id', $quoteIdBinary)->first();

            return $this->ok(200, 'Repair quote sent to customer.', ['quote' => AdminRepairQuotePresenter::present($fresh)]);
        });
    }
}
