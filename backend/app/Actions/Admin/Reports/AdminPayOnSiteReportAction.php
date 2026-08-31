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
 * The Admin Pay-on-Site Report - a read-only, exportable view over the
 * exact authoritative `booking_on_site_settlements` rows
 * App\Support\Admin\AdminFinancialSummaryCalculator already reads for the
 * Financial Dashboard's own Pay-on-Site figures - never a second query
 * engine over this table. Its summary's `collected_amount`/`pending_amount`
 * are read directly from that calculator's `compute()` output, so they can
 * never drift from the Financial Dashboard's own numbers for the same
 * range.
 *
 * `pending_amount`/`pending_count` mirror
 * AdminFinancialSummaryCalculator::payOnSitePending()'s own deliberate
 * choice to NEVER scope to the caller's date range - an uncollected
 * settlement from outside the selected window is still real money
 * outstanding today, so hiding it because it falls outside "This Month"
 * would understate the real backlog (see that method's own docblock). Row
 * filtering (`created_at` - the one timestamp every settlement always has,
 * collected or not) still respects the caller's range/status filters.
 */
final class AdminPayOnSiteReportAction
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
            return $this->unprocessable('Invalid Pay-on-Site Report filters.');
        }

        $query = $this->baseQuery($prepared);
        $total = (clone $query)->count('booking_on_site_settlements.id');
        $lastPage = max((int) ceil($total / $perPage), 1);

        $rows = $query
            ->orderByDesc('booking_on_site_settlements.created_at')
            ->orderByDesc('booking_on_site_settlements.id')
            ->forPage($page, $perPage)
            ->get($this->selectColumns());

        return $this->ok(200, 'Pay-on-Site report retrieved successfully.', [
            'settlements' => $this->normalizeRows($rows),
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

        $total = (clone $this->baseQuery($prepared))->count('booking_on_site_settlements.id');

        return [
            'summary' => $this->summary($prepared),
            'range' => $this->rangePayload($prepared),
            'rows' => $limit === null ? $this->windowedRows($prepared) : $this->normalizeRows($this->baseQuery($prepared)->orderByDesc('booking_on_site_settlements.created_at')->orderByDesc('booking_on_site_settlements.id')->limit($limit)->get($this->selectColumns())),
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
        if (isset($filters['status']) && ! in_array($filters['status'], ['PENDING', 'COLLECTED'], true)) {
            return null;
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
        $query = DB::table('booking_on_site_settlements')
            ->join('bookings', 'bookings.id', '=', 'booking_on_site_settlements.booking_id')
            ->join('carts', 'carts.id', '=', 'bookings.cart_id')
            ->leftJoin('users as collectors', 'collectors.id', '=', 'booking_on_site_settlements.collected_by_admin_user_id')
            ->where('booking_on_site_settlements.created_at', '>=', $filters['__from']->format('Y-m-d H:i:s.u'))
            ->where('booking_on_site_settlements.created_at', '<', $filters['__to']->format('Y-m-d H:i:s.u'));

        if (isset($filters['status'])) {
            $filters['status'] === 'COLLECTED'
                ? $query->whereNotNull('booking_on_site_settlements.collected_at')
                : $query->whereNull('booking_on_site_settlements.collected_at');
        }

        return $query;
    }

    /**
     * @return array<int, string>
     */
    private function selectColumns(): array
    {
        return [
            'booking_on_site_settlements.id', 'booking_on_site_settlements.amount_due',
            'booking_on_site_settlements.amount_collected', 'booking_on_site_settlements.collected_at',
            'booking_on_site_settlements.created_at', 'bookings.booking_number', 'carts.customer_user_id',
            'collectors.id as collector_id',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(array $filters): array
    {
        $calculated = AdminFinancialSummaryCalculator::compute($filters['__from'], $filters['__to']);

        $collectedCount = DB::table('booking_on_site_settlements')
            ->whereNotNull('collected_at')
            ->where('collected_at', '>=', $filters['__from']->format('Y-m-d H:i:s.u'))
            ->where('collected_at', '<', $filters['__to']->format('Y-m-d H:i:s.u'))
            ->count();

        return [
            'collected_amount' => $calculated['breakdown']['pay_on_site']['collected'],
            'collected_count' => $collectedCount,
            'pending_amount' => $calculated['breakdown']['pay_on_site']['pending'],
            'pending_count' => $calculated['bookings']['pay_on_site_pending_count'],
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
                ->orderByDesc('booking_on_site_settlements.created_at')
                ->orderByDesc('booking_on_site_settlements.id')
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
        $collectorIds = $rows->pluck('collector_id')->filter()->unique()->values()->all();

        $customers = DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->whereIn('users.id', $customerIds)
            ->get(['users.id', 'users.phone_number', 'user_profiles.full_name'])
            ->keyBy(fn ($row) => $row->id);

        $collectors = $collectorIds === [] ? collect() : DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->whereIn('users.id', $collectorIds)
            ->get(['users.id', 'user_profiles.full_name'])
            ->keyBy(fn ($row) => $row->id);

        return $rows->map(function (object $row) use ($customers, $collectors): array {
            $customer = $customers->get($row->customer_user_id);
            $collector = $row->collector_id === null ? null : $collectors->get($row->collector_id);

            return [
                'uuid' => UuidBinary::toString($row->id),
                'booking_number' => $row->booking_number,
                'customer_name' => $customer->full_name ?? null,
                'customer_phone' => $customer->phone_number ?? null,
                'amount_due' => (string) $row->amount_due,
                'amount_collected' => $row->amount_collected === null ? null : (string) $row->amount_collected,
                'status' => $row->collected_at === null ? 'PENDING' : 'COLLECTED',
                'collected_at' => $row->collected_at === null ? null : Carbon::parse($row->collected_at)->toIso8601String(),
                'collected_by' => $collector->full_name ?? null,
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            ];
        })->values()->all();
    }
}
