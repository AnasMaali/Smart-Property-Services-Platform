<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports\Financial;

use App\Actions\Admin\Reports\AdminFinancialSummaryReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GetAdminFinancialSummaryReportRequest;
use App\Support\Admin\Reports\AdminReportFilename;
use App\Support\Admin\Reports\AdminReportPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class ExportAdminFinancialSummaryReportPdfController extends Controller
{
    public function __invoke(GetAdminFinancialSummaryReportRequest $request, AdminFinancialSummaryReportAction $action): JsonResponse|Response
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

        $from = Carbon::parse($report['range']['from']);
        $to = Carbon::parse($report['range']['to']);

        return AdminReportPdf::response(
            'admin.reports.pdf.financial',
            ['report' => $report],
            AdminReportFilename::build('financial-summary', 'pdf', $from, $to)
        );
    }
}
