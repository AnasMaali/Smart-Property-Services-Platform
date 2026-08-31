<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports\Financial;

use App\Actions\Admin\Reports\AdminFinancialSummaryReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GetAdminFinancialSummaryReportRequest;
use App\Support\Admin\Reports\AdminReportCsv;
use App\Support\Admin\Reports\AdminReportFilename;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportAdminFinancialSummaryReportCsvController extends Controller
{
    public function __invoke(GetAdminFinancialSummaryReportRequest $request, AdminFinancialSummaryReportAction $action): StreamedResponse|Response
    {
        $filters = array_filter([
            'range' => $request->string('range')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $report = $action->resolveForExport($filters);

        if ($report === null) {
            return response()->json(['success' => false, 'message' => 'Invalid Financial Summary Report filters.'], 422);
        }

        $summary = $report['summary'];
        $from = Carbon::parse($report['range']['from']);
        $to = Carbon::parse($report['range']['to']);

        $rows = (function () use ($summary, $report) {
            yield [
                'TOTAL for '.$report['range']['preset'],
                $summary['gross_revenue'],
                $summary['refunds'],
                $summary['net_revenue'],
                $summary['breakdown']['credit_card'],
                $summary['breakdown']['apple_pay'],
                $summary['breakdown']['pay_on_site']['collected'],
            ];

            if ($report['breakdown_truncated']) {
                yield ['(daily breakdown omitted - selected range exceeds '.AdminFinancialSummaryReportAction::MAX_BREAKDOWN_DAYS.' days; totals above remain accurate)', '', '', '', '', '', ''];

                return;
            }

            foreach ($report['breakdown_by_day'] as $day) {
                yield [$day['date'], $day['gross_revenue'], $day['refunds'], $day['net_revenue'], $day['credit_card'], $day['apple_pay'], $day['pay_on_site_collected']];
            }
        })();

        return AdminReportCsv::stream(
            AdminReportFilename::build('financial-summary', 'csv', $from, $to),
            ['Date', 'Gross Revenue', 'Refunds', 'Net Revenue', 'Credit Card', 'Apple Pay', 'Pay on Site Collected'],
            $rows
        );
    }
}
