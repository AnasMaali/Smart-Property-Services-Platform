<?php

namespace App\Actions\Admin\Reports;

use App\Support\Admin\AdminFinancialDateRange;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The Admin Payment Report - a read-only, exportable view over the exact
 * authoritative `payment_attempts` rows App\Actions\Admin\Payment\
 * AdminListPaymentsAction already lists, never a second payment query
 * engine. Unlike App\Support\Admin\AdminFinancialSummaryCalculator (SUCCESSFUL
 * only), this report deliberately includes every status - PENDING/FAILED/
 * SUCCESSFUL/CANCELLED - since an Admin reviewing payment activity needs to
 * see failed/abandoned attempts too; `successful_amount_total` in its
 * summary is scoped to SUCCESSFUL rows only and mirrors (never duplicates
 * the arithmetic of) the calculator's own online-payment total for the same
 * window.
 *
 * Never exposes a provider credential, client_secret, raw webhook payload,
 * or a raw binary id - `provider_transaction_reference` is the same safe,
 * already-established identifier App\Support\Admin\AdminPaymentPresenter
 * exposes today.
 */
final class AdminPaymentReportAction
{
    use BuildsCartResult;

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    public const MAX_PDF_ROWS = 2000;

    private const EXPORT_WINDOW_SIZE = 500;

    /**
     * @param  array{status?: string, payment_method?: string, booking_uuid?: string, range?: string, from?: string, to?: string}  $filters
     */
    public function screen(array $filters, int $page, int $perPage): array
    {
        $page = max($page, 1);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        $prepared = $this->prepareFilters($filters);

        if ($prepared === null) {
            return $this->unprocessable('Invalid Payment Report filters.');
        }

        $query = $this->baseQuery($prepared);
        $total = (clone $query)->count('payment_attempts.id');
        $lastPage = max((int) ceil($total / $perPage), 1);

        $rows = $query
            ->orderByDesc('payment_attempts.created_at')
            ->orderByDesc('payment_attempts.id')
            ->forPage($page, $perPage)
            ->get($this->selectColumns());

        return $this->ok(200, 'Payment report retrieved successfully.', [
            'payments' => $this->normalizeRows($rows),
            'summary' => $this->summary($prepared),
            'range' => $this->rangePayload($prepared),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage],
        ]);
    }

    public function exportRows(array $filters, ?int $limit = null): ?array
    {
        $prepared = $this->prepareFilters($filters);

        if ($prepared === null) {
            return null;
        }

        $total = (clone $this->baseQuery($prepared))->count('payment_attempts.id');

        return [
            'summary' => $this->summary($prepared),
            'range' => $this->rangePayload($prepared),
            'rows' => $limit === null ? $this->windowedRows($prepared) : $this->normalizeRows($this->baseQuery($prepared)->orderByDesc('payment_attempts.created_at')->orderByDesc('payment_attempts.id')->limit($limit)->get($this->selectColumns())),
            'truncated' => $limit !== null && $total > $limit,
            'total' => $total,
        ];
    }

    /**
     * @return array{preset: string, from: string, to: string}
     */
    private function rangePayload(array $filters): array
    {
        return [
            'preset' => $filters['__preset'],
            'from' => $filters['__from']->toIso8601String(),
            'to' => $filters['__to']->toIso8601String(),
        ];
    }

    private function prepareFilters(array $filters): ?array
    {
        if (isset($filters['booking_uuid'])) {
            try {
                $filters['booking_uuid'] = UuidBinary::toBinary($filters['booking_uuid']);
            } catch (InvalidArgumentException) {
                return null;
            }
        }

        try {
            $resolved = AdminFinancialDateRange::resolve($filters['range'] ?? 'TODAY', $filters['from'] ?? null, $filters['to'] ?? null);
        } catch (InvalidArgumentException) {
            return null;
        }

        $filters['__from'] = $resolved['from'];
        $filters['__to'] = $resolved['to'];
        $filters['__preset'] = $resolved['preset'];

        return $filters;
    }

    private function baseQuery(array $filters): Builder
    {
        $query = DB::table('payment_attempts')
            ->join('carts', 'carts.id', '=', 'payment_attempts.cart_id')
            ->join('payment_statuses', 'payment_statuses.id', '=', 'payment_attempts.status_id')
            ->join('currencies', 'currencies.id', '=', 'payment_attempts.currency_id')
            ->leftJoin('bookings', 'bookings.payment_attempt_id', '=', 'payment_attempts.id')
            ->where('payment_attempts.created_at', '>=', $filters['__from']->format('Y-m-d H:i:s.u'))
            ->where('payment_attempts.created_at', '<', $filters['__to']->format('Y-m-d H:i:s.u'));

        if (isset($filters['status'])) {
            $query->where('payment_statuses.code', $filters['status']);
        }

        if (isset($filters['payment_method'])) {
            // Same CARD-vs-APPLE_PAY classification rule
            // App\Support\Admin\AdminFinancialSummaryCalculator uses -
            // never a second rule.
            $query = $filters['payment_method'] === 'APPLE_PAY'
                ? $query->where('payment_attempts.payment_method_type', 'apple_pay')
                : $query->where(fn ($q) => $q->whereNull('payment_attempts.payment_method_type')->orWhere('payment_attempts.payment_method_type', '!=', 'apple_pay'));
        }

        if (isset($filters['booking_uuid'])) {
            $query->where('bookings.id', $filters['booking_uuid']);
        }

        return $query;
    }

    /**
     * @return array<int, string>
     */
    private function selectColumns(): array
    {
        return [
            'payment_attempts.id', 'payment_attempts.checkout_reference', 'payment_attempts.requested_amount',
            'payment_attempts.confirmed_amount', 'payment_attempts.provider_transaction_reference',
            'payment_attempts.payment_method_type', 'payment_attempts.created_at',
            'payment_statuses.code as status_code', 'currencies.code as currency_code', 'currencies.symbol as currency_symbol',
            'carts.customer_user_id', 'bookings.booking_number',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(array $filters): array
    {
        $row = $this->baseQuery($filters)->selectRaw(
            "COUNT(*) as total,
             SUM(CASE WHEN payment_statuses.code = 'SUCCESSFUL' THEN 1 ELSE 0 END) as successful,
             SUM(CASE WHEN payment_statuses.code = 'FAILED' THEN 1 ELSE 0 END) as failed,
             SUM(CASE WHEN payment_statuses.code = 'PENDING' THEN 1 ELSE 0 END) as pending,
             COALESCE(SUM(CASE WHEN payment_statuses.code = 'SUCCESSFUL' THEN payment_attempts.confirmed_amount ELSE 0 END), 0) as successful_amount_total"
        )->first();

        return [
            'total_payments' => (int) ($row->total ?? 0),
            'successful_count' => (int) ($row->successful ?? 0),
            'failed_count' => (int) ($row->failed ?? 0),
            'pending_count' => (int) ($row->pending ?? 0),
            'successful_amount_total' => bcadd((string) ($row->successful_amount_total ?? '0'), '0', 6),
        ];
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function windowedRows(array $filters): \Generator
    {
        $page = 1;

        do {
            $chunk = $this->baseQuery($filters)
                ->orderByDesc('payment_attempts.created_at')
                ->orderByDesc('payment_attempts.id')
                ->forPage($page, self::EXPORT_WINDOW_SIZE)
                ->get($this->selectColumns());

            foreach ($this->normalizeRows($chunk) as $row) {
                yield $row;
            }

            $page++;
        } while ($chunk->count() === self::EXPORT_WINDOW_SIZE);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $customerIds = $rows->pluck('customer_user_id')->unique()->values()->all();

        $customers = DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->whereIn('users.id', $customerIds)
            ->get(['users.id', 'users.phone_number', 'user_profiles.full_name'])
            ->keyBy(fn ($row) => $row->id);

        return $rows->map(function (object $row) use ($customers): array {
            $customer = $customers->get($row->customer_user_id);

            return [
                'uuid' => UuidBinary::toString($row->id),
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
                'booking_number' => $row->booking_number,
                'customer_name' => $customer->full_name ?? null,
                'customer_phone' => $customer->phone_number ?? null,
                'payment_method' => $row->payment_method_type === 'apple_pay' ? 'APPLE_PAY' : 'CARD',
                'amount' => (string) ($row->confirmed_amount ?? $row->requested_amount),
                'currency_code' => $row->currency_code,
                'status' => $row->status_code,
                'provider_reference' => $row->provider_transaction_reference,
            ];
        })->values()->all();
    }
}
