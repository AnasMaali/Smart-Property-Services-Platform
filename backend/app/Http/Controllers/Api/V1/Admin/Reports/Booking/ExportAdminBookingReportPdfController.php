<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports\Booking;

use App\Actions\Admin\Reports\AdminBookingReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GetAdminBookingReportRequest;
use App\Support\Admin\Reports\AdminReportFilename;
use App\Support\Admin\Reports\AdminReportPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class ExportAdminBookingReportPdfController extends Controller
{
    public function __invoke(GetAdminBookingReportRequest $request, AdminBookingReportAction $action): Response|JsonResponse
    {
        $filters = array_filter([
            'range' => $request->string('range')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'payment_method' => $request->string('payment_method')->toString() ?: null,
            'booking_number' => $request->string('booking_number')->toString() ?: null,
            'customer_uuid' => $request->string('customer_uuid')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $export = $action->exportRows($filters, AdminBookingReportAction::MAX_PDF_ROWS);

        if ($export === null) {
            return response()->json(['success' => false, 'message' => 'Invalid Booking Report filters.'], 422);
        }

        return AdminReportPdf::response(
            'admin.reports.pdf.bookings',
            ['export' => $export, 'generatedAt' => now()->toIso8601String(), 'maxRows' => AdminBookingReportAction::MAX_PDF_ROWS],
            AdminReportFilename::build('bookings', 'pdf', Carbon::parse($export['range']['from']), Carbon::parse($export['range']['to']))
        );
    }
}
