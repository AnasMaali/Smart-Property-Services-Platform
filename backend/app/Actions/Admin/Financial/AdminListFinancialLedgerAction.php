<?php

namespace App\Actions\Admin\Financial;

use App\Support\Admin\AdminFinancialDateRange;
use App\Support\Admin\AdminFinancialLedgerPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BLUE V1 Admin Financial Ledger - a read-only, chronological UNION of
 * every real money-movement event across BLUE's separate authoritative
 * payment tables (see App\Support\Admin\AdminFinancialSummaryCalculator's
 * class docblock for the full source-of-truth map and why each of these,
 * and only these, five event types is included - Contract Billing has no
 * per-invoice amount to include). Deliberately never a materialized
 * table: every row here already lives, immutably, in `payment_attempts`,
 * `booking_on_site_settlements`, `booking_refunds`, or
 * `repair_quote_payment_attempts` - this Action only normalizes and
 * unions them for read.
 *
 * Distinct from App\Support\Admin\AdminAuditLogger's `admin_audit_logs`
 * ("who changed system state") - this is "what money actually moved",
 * never who clicked a button.
 */
final class AdminListFinancialLedgerAction
{
    use BuildsCartResult;

    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    public const EVENT_TYPES = [
        'CARD_PAYMENT',
        'APPLE_PAY_PAYMENT',
        'PAY_ON_SITE_COLLECTION',
        'REFUND',
        'REPAIR_QUOTE_BALANCE_PAYMENT',
    ];

    public const PAYMENT_METHODS = ['CARD', 'APPLE_PAY', 'PAY_ON_SITE'];

    public const DIRECTIONS = ['CREDIT', 'DEBIT'];

    /**
     * @param  array{from?: string, to?: string, event_type?: string, payment_method?: string, direction?: string, booking_uuid?: string}  $filters
     */
    public function handle(array $filters, int $page, int $perPage): array
    {
        $page = max($page, 1);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        [$from, $to] = $this->resolveDateBounds($filters);

        if ($from === 'INVALID') {
            return $this->unprocessable('The from/to dates must both be provided together, as Y-m-d, with from on or before to.');
        }

        $bookingIdBinary = null;

        if (isset($filters['booking_uuid'])) {
            try {
                $bookingIdBinary = UuidBinary::toBinary($filters['booking_uuid']);
            } catch (InvalidArgumentException) {
                return $this->ok(200, 'Financial ledger retrieved successfully.', [
                    'entries' => [],
                    'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1],
                ]);
            }
        }

        $union = $this->cardPaymentQuery($from, $to, $bookingIdBinary)
            ->unionAll($this->applePayPaymentQuery($from, $to, $bookingIdBinary))
            ->unionAll($this->payOnSiteCollectionQuery($from, $to, $bookingIdBinary))
            ->unionAll($this->refundQuery($from, $to, $bookingIdBinary))
            ->unionAll($this->repairQuoteBalancePaymentQuery($from, $to, $bookingIdBinary));

        $outer = DB::query()->fromSub($union, 'ledger');

        if (isset($filters['event_type'])) {
            $outer->where('event_type', $filters['event_type']);
        }

        if (isset($filters['payment_method'])) {
            $outer->where('payment_method', $filters['payment_method']);
        }

        if (isset($filters['direction'])) {
            $outer->where('direction', $filters['direction']);
        }

        $total = (clone $outer)->count();
        $lastPage = max((int) ceil($total / $perPage), 1);

        $rows = $outer
            ->orderByDesc('occurred_at')
            ->orderByDesc('reference_id')
            ->forPage($page, $perPage)
            ->get();

        return $this->ok(200, 'Financial ledger retrieved successfully.', [
            'entries' => AdminFinancialLedgerPresenter::presentList($rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: ?string, 1: ?string}|array{0: 'INVALID', 1: null}
     */
    private function resolveDateBounds(array $filters): array
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        if ($from === null && $to === null) {
            return [null, null];
        }

        if ($from === null || $to === null) {
            return ['INVALID', null];
        }

        try {
            $resolved = AdminFinancialDateRange::resolve('CUSTOM', $from, $to);
        } catch (InvalidArgumentException) {
            return ['INVALID', null];
        }

        return [$resolved['from']->format('Y-m-d H:i:s.u'), $resolved['to']->format('Y-m-d H:i:s.u')];
    }

    private function cardPaymentQuery(?string $from, ?string $to, ?string $bookingIdBinary)
    {
        return $this->onlinePaymentQuery($from, $to, $bookingIdBinary, applePay: false, eventType: 'CARD_PAYMENT', paymentMethod: 'CARD');
    }

    private function applePayPaymentQuery(?string $from, ?string $to, ?string $bookingIdBinary)
    {
        return $this->onlinePaymentQuery($from, $to, $bookingIdBinary, applePay: true, eventType: 'APPLE_PAY_PAYMENT', paymentMethod: 'APPLE_PAY');
    }

    private function onlinePaymentQuery(?string $from, ?string $to, ?string $bookingIdBinary, bool $applePay, string $eventType, string $paymentMethod)
    {
        $query = DB::table('payment_attempts')
            ->join('payment_statuses', 'payment_statuses.id', '=', 'payment_attempts.status_id')
            ->join('currencies', 'currencies.id', '=', 'payment_attempts.currency_id')
            ->leftJoin('bookings', 'bookings.payment_attempt_id', '=', 'payment_attempts.id')
            ->where('payment_statuses.code', 'SUCCESSFUL')
            ->where('currencies.code', 'AED')
            ->whereNotNull('payment_attempts.successful_at');

        $query = $applePay
            ? $query->where('payment_attempts.payment_method_type', 'apple_pay')
            : $query->where(fn ($q) => $q->whereNull('payment_attempts.payment_method_type')->orWhere('payment_attempts.payment_method_type', '!=', 'apple_pay'));

        $this->applyDateBounds($query, 'payment_attempts.successful_at', $from, $to);
        $this->applyBookingFilter($query, 'bookings.id', $bookingIdBinary);

        return $query->selectRaw(
            "? as event_type, 'CREDIT' as direction, payment_attempts.id as reference_id, payment_attempts.confirmed_amount as amount, payment_attempts.currency_id as currency_id, payment_attempts.successful_at as occurred_at, bookings.id as booking_id, ? as payment_method, 'SUCCESSFUL' as status",
            [$eventType, $paymentMethod]
        );
    }

    private function payOnSiteCollectionQuery(?string $from, ?string $to, ?string $bookingIdBinary)
    {
        $aedCurrencyId = (int) (DB::table('currencies')->where('code', 'AED')->value('id') ?? 0);

        $query = DB::table('booking_on_site_settlements')->whereNotNull('collected_at');

        $this->applyDateBounds($query, 'collected_at', $from, $to);
        $this->applyBookingFilter($query, 'booking_id', $bookingIdBinary);

        return $query->selectRaw(
            "'PAY_ON_SITE_COLLECTION' as event_type, 'CREDIT' as direction, id as reference_id, amount_collected as amount, ? as currency_id, collected_at as occurred_at, booking_id as booking_id, 'PAY_ON_SITE' as payment_method, 'SUCCESSFUL' as status",
            [$aedCurrencyId]
        );
    }

    private function refundQuery(?string $from, ?string $to, ?string $bookingIdBinary)
    {
        $query = DB::table('booking_refunds')
            ->join('currencies', 'currencies.id', '=', 'booking_refunds.currency_id')
            ->leftJoin('payment_attempts', 'payment_attempts.id', '=', 'booking_refunds.payment_attempt_id')
            ->where('currencies.code', 'AED')
            ->whereNotNull('booking_refunds.succeeded_at');

        $this->applyDateBounds($query, 'booking_refunds.succeeded_at', $from, $to);
        $this->applyBookingFilter($query, 'booking_refunds.booking_id', $bookingIdBinary);

        return $query->selectRaw(
            "'REFUND' as event_type, 'DEBIT' as direction, booking_refunds.id as reference_id, booking_refunds.requested_amount as amount, booking_refunds.currency_id as currency_id, booking_refunds.succeeded_at as occurred_at, booking_refunds.booking_id as booking_id, CASE WHEN CONVERT(payment_attempts.payment_method_type USING utf8mb4) = 'apple_pay' THEN 'APPLE_PAY' ELSE 'CARD' END as payment_method, 'SUCCESSFUL' as status"
        );
    }

    private function repairQuoteBalancePaymentQuery(?string $from, ?string $to, ?string $bookingIdBinary)
    {
        $query = DB::table('repair_quote_payment_attempts')
            ->join('payment_statuses', 'payment_statuses.id', '=', 'repair_quote_payment_attempts.status_id')
            ->join('currencies', 'currencies.id', '=', 'repair_quote_payment_attempts.currency_id')
            ->join('booking_item_repair_quotes', 'booking_item_repair_quotes.id', '=', 'repair_quote_payment_attempts.quote_id')
            ->where('payment_statuses.code', 'SUCCESSFUL')
            ->where('currencies.code', 'AED')
            ->whereNotNull('repair_quote_payment_attempts.successful_at');

        $this->applyDateBounds($query, 'repair_quote_payment_attempts.successful_at', $from, $to);
        $this->applyBookingFilter($query, 'booking_item_repair_quotes.booking_id', $bookingIdBinary);

        return $query->selectRaw(
            "'REPAIR_QUOTE_BALANCE_PAYMENT' as event_type, 'CREDIT' as direction, repair_quote_payment_attempts.id as reference_id, repair_quote_payment_attempts.confirmed_amount as amount, repair_quote_payment_attempts.currency_id as currency_id, repair_quote_payment_attempts.successful_at as occurred_at, booking_item_repair_quotes.booking_id as booking_id, CONVERT(repair_quote_payment_attempts.payment_method_code USING utf8mb4) as payment_method, 'SUCCESSFUL' as status"
        );
    }

    private function applyDateBounds($query, string $column, ?string $from, ?string $to): void
    {
        if ($from !== null) {
            $query->where($column, '>=', $from);
        }

        if ($to !== null) {
            $query->where($column, '<', $to);
        }
    }

    private function applyBookingFilter($query, string $column, ?string $bookingIdBinary): void
    {
        if ($bookingIdBinary !== null) {
            $query->where($column, $bookingIdBinary);
        }
    }
}
