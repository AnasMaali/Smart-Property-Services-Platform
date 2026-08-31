<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports\Payment;

use App\Actions\Admin\Reports\AdminPaymentReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GetAdminPaymentReportRequest;
use App\Support\Admin\Reports\AdminReportFilename;
use App\Support\Admin\Reports\AdminReportPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class ExportAdminPaymentReportPdfController extends Controller
{
    public function __invoke(GetAdminPaymentReportRequest $request, AdminPaymentReportAction $action): Response|JsonResponse
    {
        $filters = array_filter([
            'range' => $request->string('range')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'payment_method' => $request->string('payment_method')->toString() ?: null,
            'booking_uuid' => $request->string('booking_uuid')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $export = $action->exportRows($filters, AdminPaymentReportAction::MAX_PDF_ROWS);

        if ($export === null) {
            return response()->json(['success' => false, 'message' => 'Invalid Payment Report filters.'], 422);
        }

        return AdminReportPdf::response(
            'admin.reports.pdf.payments',
            ['export' => $export, 'generatedAt' => now()->toIso8601String(), 'maxRows' => AdminPaymentReportAction::MAX_PDF_ROWS],
            AdminReportFilename::build('payments', 'pdf', Carbon::parse($export['range']['from']), Carbon::parse($export['range']['to']))
        );
    }
}
