<?php

namespace App\Support\Booking;

use App\Support\Payment\PaymentStatuses;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B25 - whether a repair quote's balance is fully funded is
 * always DERIVED, never stored on `booking_item_repair_quotes` itself (see
 * that table's docblock in database/phase25_inspection_quote_credit_
 * migration.sql for why there is deliberately no `PAID`/`FUNDED` quote
 * status). A quote row is NEVER written to again once ACCEPTED just
 * because a balance payment succeeded - this class is the only place that
 * combines the quote's own immutable `balance_due_amount` with its
 * `repair_quote_payment_attempts` history to answer "is this quote fully
 * funded".
 */
final class RepairQuoteFundingStatus
{
    public const NOT_APPLICABLE = 'NOT_APPLICABLE';

    public const AWAITING_ACCEPTANCE = 'AWAITING_ACCEPTANCE';

    public const AWAITING_BALANCE_PAYMENT = 'AWAITING_BALANCE_PAYMENT';

    public const FULLY_FUNDED = 'FULLY_FUNDED';

    public static function for(object $quote): string
    {
        $statusCode = BookingItemRepairQuoteStatuses::code((int) $quote->status_id);

        if (! in_array($statusCode, ['SENT', 'ACCEPTED'], true)) {
            return self::NOT_APPLICABLE;
        }

        if ($statusCode === 'SENT') {
            return self::AWAITING_ACCEPTANCE;
        }

        if (bccomp((string) $quote->balance_due_amount, '0', 6) === 0) {
            return self::FULLY_FUNDED;
        }

        $hasSuccessfulBalancePayment = DB::table('repair_quote_payment_attempts')
            ->where('quote_id', $quote->id)
            ->where('status_id', PaymentStatuses::id('SUCCESSFUL'))
            ->exists();

        return $hasSuccessfulBalancePayment ? self::FULLY_FUNDED : self::AWAITING_BALANCE_PAYMENT;
    }
}
