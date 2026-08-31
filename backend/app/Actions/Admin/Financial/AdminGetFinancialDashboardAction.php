<?php

namespace App\Actions\Admin\Financial;

use App\Support\Admin\AdminFinancialDateRange;
use App\Support\Admin\AdminFinancialSummaryCalculator;
use App\Support\Cart\Concerns\BuildsCartResult;
use InvalidArgumentException;

/**
 * BLUE V1 Admin Financial Dashboard - the dedicated financial reporting
 * surface (distinct from App\Actions\Admin\Dashboard\
 * AdminGetDashboardAction's small operational snapshot). Every number is
 * computed by App\Support\Admin\AdminFinancialSummaryCalculator, the one
 * shared source of truth also used for the main Dashboard's own financial
 * snapshot - this Action's only job is resolving the caller's requested
 * date range and shaping the response.
 */
final class AdminGetFinancialDashboardAction
{
    use BuildsCartResult;

    /**
     * @param  array{range?: string, from?: string, to?: string}  $filters
     */
    public function handle(array $filters): array
    {
        $range = $filters['range'] ?? 'TODAY';

        try {
            $resolved = AdminFinancialDateRange::resolve($range, $filters['from'] ?? null, $filters['to'] ?? null);
        } catch (InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        }

        $summary = AdminFinancialSummaryCalculator::compute($resolved['from'], $resolved['to']);

        return $this->ok(200, 'Financial dashboard retrieved successfully.', [
            'range' => [
                'preset' => $resolved['preset'],
                'from' => $resolved['from']->toIso8601String(),
                'to' => $resolved['to']->toIso8601String(),
            ],
            'summary' => $summary,
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
