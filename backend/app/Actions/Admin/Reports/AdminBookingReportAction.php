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
 * The Admin Booking Report - a read-only, exportable view over the exact
 * authoritative `bookings` rows App\Actions\Admin\Booking\
 * AdminListBookingsAction already lists, never a second Booking query
 * engine. `total` is always a historical `booking_items.line_total_amount`
 * snapshot sum (the same field App\Support\Admin\AdminBookingPresenter's
 * list shape sums) - never re-derived from a Service's current price, so a
 * later price change can never move a past Booking's reported amount (see
 * this feature's historical-safety regression test).
 *
 * Screen (`screen()`), CSV (`exportRows()` unbounded, streamed in windows),
 * and PDF (`exportRows()` capped - see
 * App\Http\Controllers\Api\V1\Admin\Reports\Booking\*) all build their rows
 * from the same `normalizeRows()` - the three surfaces can never disagree
 * about what one Booking's report row contains.
 */
final class AdminBookingReportAction
{
    use BuildsCartResult;

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * A PDF renders synchronously in one process - unlike the CSV export
     * (streamed in windows, see exportRows()), it must stay bounded. Beyond
     * this many rows a PDF export is truncated (never silently - see
     * App\Http\Controllers\Api\V1\Admin\Reports\Booking\
     * ExportAdminBookingReportPdfController) and the Admin is pointed at the
     * CSV export for the complete result set.
     */
    public const MAX_PDF_ROWS = 2000;

    private const EXPORT_WINDOW_SIZE = 500;

    /**
     * @param  array{status?: string, payment_method?: string, booking_number?: string, customer_uuid?: string, range?: string, from?: string, to?: string}  $filters
     */
    public function screen(array $filters, int $page, int $perPage): array
    {
        $page = max($page, 1);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        $prepared = $this->prepareFilters($filters);

        if ($prepared === null) {
            return $this->unprocessable('Invalid Booking Report filters.');
        }

        $query = $this->baseQuery($prepared);

        $total = (clone $query)->count('bookings.id');
        $lastPage = max((int) ceil($total / $perPage), 1);

        $rows = $query
            ->orderByDesc('bookings.created_at')
            ->orderByDesc('bookings.id')
            ->forPage($page, $perPage)
            ->get($this->selectColumns());

        return $this->ok(200, 'Booking report retrieved successfully.', [
            'bookings' => $this->normalizeRows($rows),
            'summary' => $this->summary($prepared),
            'range' => $this->rangePayload($prepared),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage],
        ]);
    }

    /**
     * @param  array{status?: string, payment_method?: string, booking_number?: string, customer_uuid?: string, range?: string, from?: string, to?: string}  $filters
     * @return array{summary: array<string, mixed>, range: array<string, mixed>, rows: iterable<int, array<string, mixed>>, truncated: bool, total: int}|null
     */
    public function exportRows(array $filters, ?int $limit = null): ?array
    {
        $prepared = $this->prepareFilters($filters);

        if ($prepared === null) {
            return null;
        }

        $total = (clone $this->baseQuery($prepared))->count('bookings.id');

        return [
            'summary' => $this->summary($prepared),
            'range' => $this->rangePayload($prepared),
            'rows' => $limit === null ? $this->windowedRows($prepared) : $this->normalizeRows($this->baseQuery($prepared)->orderByDesc('bookings.created_at')->orderByDesc('bookings.id')->limit($limit)->get($this->selectColumns())),
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

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    private function prepareFilters(array $filters): ?array
    {
        if (isset($filters['customer_uuid'])) {
            try {
                $filters['customer_uuid'] = UuidBinary::toBinary($filters['customer_uuid']);
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
        $query = DB::table('bookings')
            ->join('carts', 'carts.id', '=', 'bookings.cart_id')
            ->join('booking_statuses', 'booking_statuses.id', '=', 'bookings.status_id')
            ->join('booking_sources', 'booking_sources.id', '=', 'bookings.booking_source_id')
            ->where('bookings.created_at', '>=', $filters['__from']->format('Y-m-d H:i:s.u'))
            ->where('bookings.created_at', '<', $filters['__to']->format('Y-m-d H:i:s.u'));

        if (isset($filters['status'])) {
            $query->where('booking_statuses.code', $filters['status']);
        }

        if (isset($filters['payment_method'])) {
            $query->where('bookings.payment_method_code', $filters['payment_method']);
        }

        if (isset($filters['booking_number'])) {
            $query->where('bookings.booking_number', $filters['booking_number']);
        }

        if (isset($filters['customer_uuid'])) {
            $query->where('carts.customer_user_id', $filters['customer_uuid']);
        }

        return $query;
    }

    /**
     * @return array<int, string>
     */
    private function selectColumns(): array
    {
        return [
            'bookings.id', 'bookings.booking_number', 'bookings.status_id', 'bookings.booking_source_id',
            'bookings.payment_method_code', 'bookings.appointment_slot_id', 'bookings.created_at',
            'booking_statuses.code as status_code', 'booking_sources.code as source_code',
            'carts.customer_user_id', 'carts.currency_id as cart_currency_id',
        ];
    }

    /**
     * @return array<string, int>
     */
    private function summary(array $filters): array
    {
        $row = $this->baseQuery($filters)->selectRaw(
            "COUNT(*) as total,
             SUM(CASE WHEN booking_statuses.code = 'COMPLETED' THEN 1 ELSE 0 END) as completed,
             SUM(CASE WHEN booking_statuses.code = 'CANCELLED' THEN 1 ELSE 0 END) as cancelled,
             SUM(CASE WHEN booking_statuses.code IN ('CONFIRMED','PAID','ASSIGNED','IN_PROGRESS') THEN 1 ELSE 0 END) as active"
        )->first();

        return [
            'total_bookings' => (int) ($row->total ?? 0),
            'completed' => (int) ($row->completed ?? 0),
            'cancelled' => (int) ($row->cancelled ?? 0),
            'active' => (int) ($row->active ?? 0),
        ];
    }

    /**
     * Windowed (offset-paginated, not a single unbounded query) CSV row
     * source - each window batch-loads its own services/customers exactly
     * like normalizeRows() below, so a multi-thousand-row export never
     * holds the whole Booking table in memory at once.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function windowedRows(array $filters): \Generator
    {
        $page = 1;

        do {
            $chunk = $this->baseQuery($filters)
                ->orderByDesc('bookings.created_at')
                ->orderByDesc('bookings.id')
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

        $bookingIds = $rows->pluck('id')->all();
        $customerIds = $rows->pluck('customer_user_id')->unique()->values()->all();
        $currencyIds = $rows->pluck('cart_currency_id')->unique()->values()->all();
        $slotIds = $rows->pluck('appointment_slot_id')->unique()->values()->all();

        $totals = DB::table('booking_items')
            ->whereIn('booking_id', $bookingIds)
            ->selectRaw('booking_id, SUM(line_total_amount) as total')
            ->groupBy('booking_id')
            ->get()
            ->keyBy(fn ($row) => $row->booking_id);

        $serviceNames = DB::table('booking_items')
            ->whereIn('booking_id', $bookingIds)
            ->orderBy('display_order')
            ->get(['booking_id', 'service_name_snapshot'])
            ->groupBy('booking_id');

        $customers = DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->whereIn('users.id', $customerIds)
            ->get(['users.id', 'users.phone_number', 'user_profiles.full_name'])
            ->keyBy(fn ($row) => $row->id);

        $currencies = DB::table('currencies')
            ->whereIn('id', $currencyIds)
            ->get(['id', 'code', 'symbol', 'minor_unit'])
            ->keyBy(fn ($row) => $row->id);

        $slots = DB::table('appointment_slots')
            ->whereIn('id', $slotIds)
            ->get(['id', 'starts_at'])
            ->keyBy('id');

        return $rows->map(function (object $row) use ($totals, $serviceNames, $customers, $currencies, $slots): array {
            $customer = $customers->get($row->customer_user_id);
            $currency = $currencies->get($row->cart_currency_id);
            $slot = $slots->get($row->appointment_slot_id);

            return [
                'uuid' => UuidBinary::toString($row->id),
                'booking_number' => $row->booking_number,
                'customer_name' => $customer->full_name ?? null,
                'customer_phone' => $customer->phone_number ?? null,
                'status' => $row->status_code,
                'source' => $row->source_code,
                'services' => ($serviceNames->get($row->id) ?? collect())->pluck('service_name_snapshot')->implode(', '),
                'appointment_at' => $slot === null ? null : Carbon::parse($slot->starts_at)->toIso8601String(),
                'payment_method' => $row->payment_method_code,
                'total' => (string) ($totals->get($row->id)->total ?? '0.000000'),
                'currency_code' => $currency->code ?? null,
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            ];
        })->values()->all();
    }
}
