<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports\Refund;

use App\Actions\Admin\Reports\AdminRefundReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GetAdminRefundReportRequest;
use App\Support\Admin\Reports\AdminReportFilename;
use App\Support\Admin\Reports\AdminReportPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class ExportAdminRefundReportPdfController extends Controller
{
    public function __invoke(GetAdminRefundReportRequest $request, AdminRefundReportAction $action): Response|JsonResponse
    {
        $filters = array_filter([
            'range' => $request->string('range')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $export = $action->exportRows($filters, AdminRefundReportAction::MAX_PDF_ROWS);

        if ($export === null) {
            return response()->json(['success' => false, 'message' => 'Invalid Refund Report filters.'], 422);
        }

        return AdminReportPdf::response(
            'admin.reports.pdf.refunds',
            ['export' => $export, 'generatedAt' => now()->toIso8601String(), 'maxRows' => AdminRefundReportAction::MAX_PDF_ROWS],
            AdminReportFilename::build('refunds', 'pdf', Carbon::parse($export['range']['from']), Carbon::parse($export['range']['to']))
        );
    }
}
