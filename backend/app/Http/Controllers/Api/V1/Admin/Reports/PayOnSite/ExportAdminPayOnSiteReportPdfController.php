<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports\PayOnSite;

use App\Actions\Admin\Reports\AdminPayOnSiteReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GetAdminPayOnSiteReportRequest;
use App\Support\Admin\Reports\AdminReportFilename;
use App\Support\Admin\Reports\AdminReportPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class ExportAdminPayOnSiteReportPdfController extends Controller
{
    public function __invoke(GetAdminPayOnSiteReportRequest $request, AdminPayOnSiteReportAction $action): Response|JsonResponse
    {
        $filters = array_filter([
            'range' => $request->string('range')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $export = $action->exportRows($filters, AdminPayOnSiteReportAction::MAX_PDF_ROWS);

        if ($export === null) {
            return response()->json(['success' => false, 'message' => 'Invalid Pay-on-Site Report filters.'], 422);
        }

        return AdminReportPdf::response(
            'admin.reports.pdf.pay-on-site',
            ['export' => $export, 'generatedAt' => now()->toIso8601String(), 'maxRows' => AdminPayOnSiteReportAction::MAX_PDF_ROWS],
            AdminReportFilename::build('pay-on-site', 'pdf', Carbon::parse($export['range']['from']), Carbon::parse($export['range']['to']))
        );
    }
}
