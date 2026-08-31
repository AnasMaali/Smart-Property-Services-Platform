<?php

namespace App\Support\Admin;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one place BLUE V1's Admin Financial Dashboard summary numbers are
 * computed - reused as-is by both App\Actions\Admin\Financial\
 * AdminGetFinancialDashboardAction (a caller-chosen date range) and
 * App\Actions\Admin\Dashboard\AdminGetDashboardAction's small financial
 * snapshot (a fixed rolling last-24h window), so the two Admin surfaces can
 * never disagree about what "Gross Revenue" means. bcmath only for every
 * amount - matching App\Support\Payment\Gateway\MinorUnitConverter's
 * convention - never float arithmetic on money.
 *
 * BLUE V1 is AED-only (database/blue_v1_seed.sql "CURRENCIES") but the
 * schema technically allows more than one `currencies` row in the future -
 * every source table that carries a `currency_id` is therefore explicitly
 * constrained to `currencies.code = 'AED'` rather than summed unconditionally,
 * so a future non-AED row can never silently blend into these totals.
 * `booking_on_site_settlements` has no `currency_id` column at all (an
 * on-site settlement is implicitly denominated in its Booking's own Cart
 * currency) - safe only because BLUE V1 truly has no other currency any
 * booking can reach; see this class's own PHPDoc on payOnSite() for the
 * one place that assumption lives.
 *
 * Money-source map (never double-counted - see each private method's
 * docblock for why):
 * - Card/Apple Pay revenue: `payment_attempts` (status SUCCESSFUL),
 *   classified by `payment_method_type` using the exact same
 *   `=== 'apple_pay' ? APPLE_PAY : CARD` rule App\Actions\Booking\
 *   CreateBookingFromSuccessfulPaymentAction::paymentMethodCodeFor()
 *   already uses to write `bookings.payment_method_code`.
 * - Pay-on-Site revenue: `booking_on_site_settlements.amount_collected`
 *   (collected_at IS NOT NULL) - a wholly separate table
 *   App\Actions\Admin\Booking\AdminCollectOnSitePaymentAction's own
 *   docblock confirms never touches `payment_attempts`.
 * - Repair Quote balance revenue: `repair_quote_payment_attempts` (status
 *   SUCCESSFUL) - BLUE V1 Phase B25's own dedicated, Cart-less ledger,
 *   deliberately never a `payment_attempts` row (see that table's own
 *   schema comment in database/blue_v1_schema.sql). The matching
 *   `repair_quote_credits` row is never counted as revenue here - it is a
 *   credit applied against the balance due, not new cash; the cash it
 *   represents was already counted once, when the original inspection
 *   `payment_attempts` row succeeded.
 * - Refunds: `booking_refunds` (succeeded_at IS NOT NULL) - the ONLY
 *   refund-with-a-persisted-amount table in BLUE V1. An on-site
 *   settlement's `refund_status = 'MANUAL_REFUND_REQUIRED'` flag (cash
 *   handed back by an Admin outside the system) has no persisted amount
 *   and is therefore never subtracted here - see payOnSite()'s docblock.
 * - Contract Billing: deliberately excluded from every total in this
 *   class. `service_contract_billings` persists only the current
 *   subscription-level snapshot (status/period/recurring_amount) and
 *   `service_contract_billing_webhook_events` persists no amount at all -
 *   BLUE V1 has no per-invoice-payment row (amount + timestamp) anywhere
 *   in the schema, so there is no authoritative source to safely count
 *   one collected Contract invoice without guessing. Including it would
 *   mean approximating "recurring_amount x number of elapsed periods",
 *   which is not a real record of money that moved and could silently
 *   diverge from what Stripe actually collected (a skipped/failed/
 *   prorated invoice). Revisit only once a per-invoice-payment ledger
 *   table exists.
 */
final class AdminFinancialSummaryCalculator
{
    /**
     * @return array<string, mixed>
     */
    public static function compute(Carbon $from, Carbon $to): array
    {
        // Pre-formatted datetime(6)-safe strings - binding a Carbon
        // DateTimeInterface directly truncates to whole-second precision
        // (same caveat App\Support\Payment\PaymentAttemptStateMachine's
        // own docblock calls out), which would misclassify a row whose
        // `successful_at`/`collected_at` shares the exact boundary second.
        $from = $from->format('Y-m-d H:i:s.u');
        $to = $to->format('Y-m-d H:i:s.u');

        $currency = self::aedCurrency();

        $cardPayments = self::paymentAttemptsTotal($from, $to, false);
        $applePayPayments = self::paymentAttemptsTotal($from, $to, true);
        $repairQuoteCard = self::repairQuoteBalanceTotal($from, $to, 'CARD');
        $repairQuoteApplePay = self::repairQuoteBalanceTotal($from, $to, 'APPLE_PAY');
        $payOnSiteCollected = self::payOnSiteCollected($from, $to);
        $payOnSitePending = self::payOnSitePending();
        $refunds = self::refundsTotal($from, $to);

        $creditCardTotal = bcadd($cardPayments['amount'], $repairQuoteCard['amount'], 6);
        $applePayTotal = bcadd($applePayPayments['amount'], $repairQuoteApplePay['amount'], 6);
        $repairQuoteBalanceTotal = bcadd($repairQuoteCard['amount'], $repairQuoteApplePay['amount'], 6);

        $grossRevenue = bcadd(bcadd($creditCardTotal, $applePayTotal, 6), $payOnSiteCollected['amount'], 6);
        $netRevenue = bcsub($grossRevenue, $refunds['amount'], 6);

        return [
            'currency' => $currency,
            'gross_revenue' => $grossRevenue,
            'refunds' => $refunds['amount'],
            'net_revenue' => $netRevenue,
            'breakdown' => [
                'credit_card' => $creditCardTotal,
                'apple_pay' => $applePayTotal,
                'pay_on_site' => [
                    'collected' => $payOnSiteCollected['amount'],
                    'pending' => $payOnSitePending['amount'],
                ],
            ],
            'bookings' => [
                'paid_count' => $cardPayments['count'] + $applePayPayments['count'],
                'refunded_count' => $refunds['count'],
                'pay_on_site_pending_count' => $payOnSitePending['count'],
            ],
            'repair_quote_balance_collected' => $repairQuoteBalanceTotal,
        ];
    }

    /**
     * @return array{code: string, symbol: ?string, decimal_places: int}
     */
    private static function aedCurrency(): array
    {
        $row = DB::table('currencies')->where('code', 'AED')->first(['code', 'symbol', 'minor_unit']);

        return [
            'code' => $row->code ?? 'AED',
            'symbol' => $row->symbol ?? null,
            'decimal_places' => (int) ($row->minor_unit ?? 2),
        ];
    }

    /**
     * `payment_attempts.confirmed_amount` for SUCCESSFUL attempts whose
     * `successful_at` falls in [$from, $to), classified CARD vs APPLE_PAY
     * by the exact same rule App\Actions\Booking\
     * CreateBookingFromSuccessfulPaymentAction::paymentMethodCodeFor()
     * uses - never a value the client declared.
     *
     * @return array{amount: string, count: int}
     */
    private static function paymentAttemptsTotal(string $from, string $to, bool $applePay): array
    {
        $query = DB::table('payment_attempts')
            ->join('payment_statuses', 'payment_statuses.id', '=', 'payment_attempts.status_id')
            ->join('currencies', 'currencies.id', '=', 'payment_attempts.currency_id')
            ->where('payment_statuses.code', 'SUCCESSFUL')
            ->where('currencies.code', 'AED')
            ->where('payment_attempts.successful_at', '>=', $from)
            ->where('payment_attempts.successful_at', '<', $to);

        $query = $applePay
            ? $query->where('payment_attempts.payment_method_type', 'apple_pay')
            : $query->where(fn ($q) => $q->whereNull('payment_attempts.payment_method_type')->orWhere('payment_attempts.payment_method_type', '!=', 'apple_pay'));

        $row = $query->selectRaw('COALESCE(SUM(payment_attempts.confirmed_amount), 0) as total, COUNT(*) as total_count')->first();

        return ['amount' => bcadd((string) $row->total, '0', 6), 'count' => (int) $row->total_count];
    }

    /**
     * `repair_quote_payment_attempts.confirmed_amount` for SUCCESSFUL
     * balance-payment attempts whose `successful_at` falls in
     * [$from, $to), by the table's own already-normalized
     * `payment_method_code` (CARD/APPLE_PAY - a database CHECK
     * constraint, never guessed here).
     *
     * @return array{amount: string, count: int}
     */
    private static function repairQuoteBalanceTotal(string $from, string $to, string $paymentMethodCode): array
    {
        $row = DB::table('repair_quote_payment_attempts')
            ->join('payment_statuses', 'payment_statuses.id', '=', 'repair_quote_payment_attempts.status_id')
            ->join('currencies', 'currencies.id', '=', 'repair_quote_payment_attempts.currency_id')
            ->where('payment_statuses.code', 'SUCCESSFUL')
            ->where('currencies.code', 'AED')
            ->where('repair_quote_payment_attempts.payment_method_code', $paymentMethodCode)
            ->where('repair_quote_payment_attempts.successful_at', '>=', $from)
            ->where('repair_quote_payment_attempts.successful_at', '<', $to)
            ->selectRaw('COALESCE(SUM(repair_quote_payment_attempts.confirmed_amount), 0) as total, COUNT(*) as total_count')
            ->first();

        return ['amount' => bcadd((string) $row->total, '0', 6), 'count' => (int) $row->total_count];
    }

    /**
     * `booking_on_site_settlements.amount_collected` for settlements
     * actually collected (`collected_at` IS NOT NULL) in [$from, $to).
     * `amount_collected` is only ever set once, to exactly `amount_due`,
     * by App\Actions\Admin\Booking\AdminCollectOnSitePaymentAction's
     * transaction (UNIQUE(booking_id) on this table also makes a second
     * collection row for the same Booking structurally impossible) - so
     * this can never double-count one Booking's on-site cash.
     *
     * No `currency_id` column exists on this table to constrain to AED -
     * safe here only because every Pay-on-Site Booking in BLUE V1 is
     * created through App\Actions\Booking\CreatePayOnSiteBookingAction
     * against a Cart that itself has no non-AED currency reachable
     * anywhere in this codebase (database/blue_v1_seed.sql seeds exactly
     * one `currencies` row). If BLUE ever seeds a second currency, this
     * table needs its own `currency_id` before this total can stay safe.
     *
     * @return array{amount: string, count: int}
     */
    private static function payOnSiteCollected(string $from, string $to): array
    {
        $row = DB::table('booking_on_site_settlements')
            ->whereNotNull('collected_at')
            ->where('collected_at', '>=', $from)
            ->where('collected_at', '<', $to)
            ->selectRaw('COALESCE(SUM(amount_collected), 0) as total, COUNT(*) as total_count')
            ->first();

        return ['amount' => bcadd((string) $row->total, '0', 6), 'count' => (int) $row->total_count];
    }

    /**
     * The CURRENT global outstanding Pay-on-Site balance
     * (`collected_at IS NULL`) - deliberately NOT constrained to the
     * caller's `[$from, $to)` window. Unlike Gross/Refunds/Net (money that
     * moved during a period), "how much cash is still owed to us right
     * now" is a point-in-time balance an Admin needs regardless of which
     * reporting period they happen to have selected - an uncollected
     * Booking from last month is still real money outstanding today, and
     * hiding it because it falls outside "This Month" would understate
     * the real backlog. `amount_due` (never a client-supplied amount) is
     * frozen at Pay-on-Site Booking creation, so this is not a "current
     * price" figure a later Service price change could retroactively
     * move.
     *
     * @return array{amount: string, count: int}
     */
    private static function payOnSitePending(): array
    {
        $row = DB::table('booking_on_site_settlements')
            ->whereNull('collected_at')
            ->selectRaw('COALESCE(SUM(amount_due), 0) as total, COUNT(*) as total_count')
            ->first();

        return ['amount' => bcadd((string) $row->total, '0', 6), 'count' => (int) $row->total_count];
    }

    /**
     * `booking_refunds.requested_amount` for refunds that actually
     * SUCCEEDED (`succeeded_at` IS NOT NULL) in [$from, $to) -
     * `requested_amount` is the final, frozen refund amount for a
     * succeeded row (BLUE V1's refund policy percentage is computed once,
     * at cancellation time, and never changes afterward - see
     * App\Support\Booking\RefundEligibilityCalculator), so it is safe to
     * treat as the actual amount refunded. A PENDING/FAILED refund
     * subtracts nothing - see App\Support\Booking\BookingRefundStatuses's
     * docblock for the full status vocabulary this deliberately does not
     * treat as final.
     *
     * @return array{amount: string, count: int}
     */
    private static function refundsTotal(string $from, string $to): array
    {
        $row = DB::table('booking_refunds')
            ->join('currencies', 'currencies.id', '=', 'booking_refunds.currency_id')
            ->where('currencies.code', 'AED')
            ->whereNotNull('booking_refunds.succeeded_at')
            ->where('booking_refunds.succeeded_at', '>=', $from)
            ->where('booking_refunds.succeeded_at', '<', $to)
            ->selectRaw('COALESCE(SUM(booking_refunds.requested_amount), 0) as total, COUNT(*) as total_count')
            ->first();

        return ['amount' => bcadd((string) $row->total, '0', 6), 'count' => (int) $row->total_count];
    }
}
