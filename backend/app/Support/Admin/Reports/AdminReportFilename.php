<?php

namespace App\Support\Admin\Reports;

use Illuminate\Support\Carbon;

/**
 * Deterministic, filesystem/header-safe filenames for every BLUE V1 Admin
 * Report export - `$reportType` is always one of this feature's own fixed
 * report-name constants (never user input), so no further sanitization of
 * it is needed; the date range came from App\Support\Admin\
 * AdminFinancialDateRange::resolve(), already constrained to real
 * Y-m-d-derived Carbon instances.
 */
final class AdminReportFilename
{
    public static function build(string $reportType, string $extension, ?Carbon $from = null, ?Carbon $to = null): string
    {
        $datePart = ($from !== null && $to !== null)
            ? $from->format('Ymd').'-'.$to->format('Ymd')
            : now()->format('Ymd-His');

        return "blue-{$reportType}-report_{$datePart}.{$extension}";
    }
}
