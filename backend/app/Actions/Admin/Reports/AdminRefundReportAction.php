<?php

namespace App\Actions\Admin\Reports;

use App\Support\Admin\AdminFinancialDateRange;
use App\Support\Admin\AdminFinancialSummaryCalculator;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The Admin Refund Report - a read-only, exportable view over the exact
 * authoritative `booking_refunds` rows (BLUE V1 Phase B20 automated
 * refunds), the same source table App\Support\Admin\
 * AdminFinancialSummaryCalculator's own `refundsTotal()` reads. Its summary
 * (`confirmed_count`/`confirmed_total`) is computed by calling that exact
 * calculator - never a second refund-total formula - so a Refund Report's
 * confirmed total can never drift from the Financial Dashboard's `refunds`
 * figure for the same range. The row list below additionally shows PENDING/
 * FAILED/RECONCILIATION_REQUIRED refunds (which the calculator, correctly,
 * never counts as money moved) so an Admin can see what has NOT yet
 * refunded - see summary()'s docblock for why those never affect the
 * confirmed total.
 */
final class AdminRefundReportAction
{
    use BuildsCartResult;

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    public const MAX_PDF_ROWS = 2000;

    private const EXPORT_WINDOW_SIZE = 500;

    /**
     * @param  array{status?: string, range?: string, from?: string, to?: string}  $filters
     */
    public function screen(array $filters, int $page, int $perPage): array
    {
        $page = max($page, 1);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        $prepared = $this->prepareFilters($filters);

        if ($prepared === null) {
            return $this->unprocessable('Invalid Refund Report filters.');
        }

        $query = $this->baseQuery($prepared);
        $total = (clone $query)->count('booking_refunds.id');
        $lastPage = max((int) ceil($total / $perPage), 1);

        $rows = $query
            ->orderByDesc('booking_refunds.requested_at')
            ->orderByDesc('booking_refunds.id')
            ->forPage($page, $perPage)
            ->get($this->selectColumns());

        return $this->ok(200, 'Refund report retrieved successfully.', [
            'refunds' => $this->normalizeRows($rows),
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

        $total = (clone $this->baseQuery($prepared))->count('booking_refunds.id');

        return [
            'summary' => $this->summary($prepared),
            'range' => $this->rangePayload($prepared),
            'rows' => $limit === null ? $this->windowedRows($prepared) : $this->normalizeRows($this->baseQuery($prepared)->orderByDesc('booking_refunds.requested_at')->orderByDesc('booking_refunds.id')->limit($limit)->get($this->selectColumns())),
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
        $query = DB::table('booking_refunds')
            ->join('bookings', 'bookings.id', '=', 'booking_refunds.booking_id')
            ->join('currencies', 'currencies.id', '=', 'booking_refunds.currency_id')
            ->join('booking_refund_statuses', 'booking_refund_statuses.id', '=', 'booking_refunds.status_id')
            ->leftJoin('payment_attempts', 'payment_attempts.id', '=', 'booking_refunds.payment_attempt_id')
            ->where('booking_refunds.requested_at', '>=', $filters['__from']->format('Y-m-d H:i:s.u'))
            ->where('booking_refunds.requested_at', '<', $filters['__to']->format('Y-m-d H:i:s.u'));

        if (isset($filters['status'])) {
            $query->where('booking_refund_statuses.code', $filters['status']);
        }

        return $query;
    }

    /**
     * @return array<int, string>
     */
    private function selectColumns(): array
    {
        return [
            'booking_refunds.id', 'booking_refunds.requested_amount', 'booking_refunds.reason',
            'booking_refunds.requested_at', 'booking_refunds.succeeded_at', 'booking_refunds.failed_at',
            'booking_refund_statuses.code as status_code', 'currencies.code as currency_code',
            'bookings.booking_number', 'payment_attempts.provider_transaction_reference',
        ];
    }

    /**
     * `confirmed_count`/`confirmed_total` are read directly from
     * App\Support\Admin\AdminFinancialSummaryCalculator::compute() - the
     * exact same `bookings.refunded_count` / `refunds` figures the
     * Financial Dashboard shows for this range - never a second SUM. Every
     * other count here (`pending_count`/`failed_count`) is informational
     * only and deliberately excluded from `confirmed_total`, mirroring the
     * calculator's own refundsTotal() docblock: only a SUCCEEDED refund
     * (`succeeded_at IS NOT NULL`) is money that actually moved.
     *
     * @return array<string, mixed>
     */
    private function summary(array $filters): array
    {
        $calculated = AdminFinancialSummaryCalculator::compute($filters['__from'], $filters['__to']);

        $counts = DB::table('booking_refunds')
            ->join('booking_refund_statuses', 'booking_refund_statuses.id', '=', 'booking_refunds.status_id')
            ->where('booking_refunds.requested_at', '>=', $filters['__from']->format('Y-m-d H:i:s.u'))
            ->where('booking_refunds.requested_at', '<', $filters['__to']->format('Y-m-d H:i:s.u'))
            ->selectRaw(
                "SUM(CASE WHEN booking_refund_statuses.code = 'PENDING' THEN 1 ELSE 0 END) as pending,
                 SUM(CASE WHEN booking_refund_statuses.code = 'FAILED' THEN 1 ELSE 0 END) as failed"
            )->first();

        return [
            'confirmed_count' => $calculated['bookings']['refunded_count'],
            'confirmed_total' => $calculated['refunds'],
            'pending_count' => (int) ($counts->pending ?? 0),
            'failed_count' => (int) ($counts->failed ?? 0),
            'currency' => $calculated['currency'],
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
                ->orderByDesc('booking_refunds.requested_at')
                ->orderByDesc('booking_refunds.id')
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
        return $rows->map(fn (object $row): array => [
            'uuid' => UuidBinary::toString($row->id),
            'requested_at' => Carbon::parse($row->requested_at)->toIso8601String(),
            'booking_number' => $row->booking_number,
            'original_payment_reference' => $row->provider_transaction_reference,
            'amount' => (string) $row->requested_amount,
            'currency_code' => $row->currency_code,
            'status' => $row->status_code,
            'reason' => $row->reason,
            'succeeded_at' => $row->succeeded_at === null ? null : Carbon::parse($row->succeeded_at)->toIso8601String(),
            'failed_at' => $row->failed_at === null ? null : Carbon::parse($row->failed_at)->toIso8601String(),
        ])->values()->all();
    }
}
