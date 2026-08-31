<?php

namespace App\Actions\Admin\Reports;

use App\Support\Admin\AdminFinancialDateRange;
use App\Support\Admin\AdminFinancialSummaryCalculator;
use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The Admin Financial Summary Report - the Reports-surface counterpart of
 * App\Actions\Admin\Financial\AdminGetFinancialDashboardAction. Deliberately
 * calls the exact same App\Support\Admin\AdminFinancialSummaryCalculator::
 * compute() for both the headline totals AND every row of its by-day
 * breakdown - never a second revenue calculator, and the report's totals
 * can therefore never drift from the Financial Dashboard's totals for the
 * same range. Screen JSON, CSV export, and PDF export (see
 * App\Http\Controllers\Api\V1\Admin\Reports\Financial\*) all call this one
 * `handle()` and render the one result it returns.
 */
final class AdminFinancialSummaryReportAction
{
    use BuildsCartResult;

    /**
     * A daily breakdown beyond this many calendar days would mean this many
     * extra AdminFinancialSummaryCalculator::compute() calls - each already
     * a handful of aggregate queries. Capped, not silently truncated: a
     * CUSTOM range wider than this still returns full, correct totals, just
     * with `breakdown_truncated: true` and an empty `breakdown_by_day` so
     * the caller can tell the Admin why (see this report's CSV/PDF/screen
     * renderers).
     */
    public const MAX_BREAKDOWN_DAYS = 92;

    /**
     * @param  array{range?: string, from?: string, to?: string}  $filters
     * @return array{success: bool, status: int, message: string, data?: array<string, mixed>}
     */
    public function handle(array $filters): array
    {
        $resolved = $this->resolveRange($filters);

        if ($resolved === null) {
            return $this->unprocessable('The from/to dates must be Y-m-d, with from on or before to.');
        }

        return $this->ok(200, 'Financial summary report retrieved successfully.', $this->buildReport($resolved));
    }

    /**
     * @param  array{range?: string, from?: string, to?: string}  $filters
     * @return array{success: true, data: array<string, mixed>}|null
     */
    public function resolveForExport(array $filters): ?array
    {
        $resolved = $this->resolveRange($filters);

        return $resolved === null ? null : $this->buildReport($resolved);
    }

    /**
     * @param  array{range?: string, from?: string, to?: string}  $filters
     * @return array{preset: string, from: Carbon, to: Carbon}|null
     */
    private function resolveRange(array $filters): ?array
    {
        try {
            return AdminFinancialDateRange::resolve($filters['range'] ?? 'TODAY', $filters['from'] ?? null, $filters['to'] ?? null);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  array{preset: string, from: Carbon, to: Carbon}  $resolved
     * @return array<string, mixed>
     */
    private function buildReport(array $resolved): array
    {
        $summary = AdminFinancialSummaryCalculator::compute($resolved['from'], $resolved['to']);
        $breakdown = $this->dailyBreakdown($resolved['from'], $resolved['to']);

        return [
            'range' => [
                'preset' => $resolved['preset'],
                'from' => $resolved['from']->toIso8601String(),
                'to' => $resolved['to']->toIso8601String(),
            ],
            'summary' => $summary,
            'breakdown_by_day' => $breakdown['rows'],
            'breakdown_truncated' => $breakdown['truncated'],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * One AdminFinancialSummaryCalculator::compute() call per UTC calendar
     * day in [$from, $to) - the exact same authoritative source as the
     * headline totals, just windowed to a single day at a time.
     *
     * @return array{rows: array<int, array<string, mixed>>, truncated: bool}
     */
    private function dailyBreakdown(Carbon $from, Carbon $to): array
    {
        $totalDays = $from->diffInDays($to);

        if ($totalDays > self::MAX_BREAKDOWN_DAYS) {
            return ['rows' => [], 'truncated' => true];
        }

        $rows = [];
        $cursor = $from->copy();

        while ($cursor->lessThan($to)) {
            $dayEnd = $cursor->copy()->addDay()->min($to);
            $daySummary = AdminFinancialSummaryCalculator::compute($cursor, $dayEnd);

            $rows[] = [
                'date' => $cursor->copy()->setTimezone((string) config('finance.timezone', 'Asia/Dubai'))->toDateString(),
                'gross_revenue' => $daySummary['gross_revenue'],
                'refunds' => $daySummary['refunds'],
                'net_revenue' => $daySummary['net_revenue'],
                'credit_card' => $daySummary['breakdown']['credit_card'],
                'apple_pay' => $daySummary['breakdown']['apple_pay'],
                'pay_on_site_collected' => $daySummary['breakdown']['pay_on_site']['collected'],
            ];

            $cursor->addDay();
        }

        return ['rows' => $rows, 'truncated' => false];
    }
}
