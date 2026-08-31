<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports\Booking;

use App\Actions\Admin\Reports\AdminBookingReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GetAdminBookingReportRequest;
use App\Support\Admin\Reports\AdminReportCsv;
use App\Support\Admin\Reports\AdminReportFilename;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportAdminBookingReportCsvController extends Controller
{
    public function __invoke(GetAdminBookingReportRequest $request, AdminBookingReportAction $action): StreamedResponse|JsonResponse
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

        $export = $action->exportRows($filters);

        if ($export === null) {
            return response()->json(['success' => false, 'message' => 'Invalid Booking Report filters.'], 422);
        }

        $rows = (function () use ($export) {
            foreach ($export['rows'] as $row) {
                yield [
                    $row['booking_number'], $row['customer_name'], $row['customer_phone'], $row['status'],
                    $row['source'], $row['services'], $row['appointment_at'], $row['payment_method'],
                    $row['total'], $row['currency_code'], $row['created_at'],
                ];
            }
        })();

        return AdminReportCsv::stream(
            AdminReportFilename::build('bookings', 'csv', Carbon::parse($export['range']['from']), Carbon::parse($export['range']['to'])),
            ['Booking Number', 'Customer Name', 'Customer Phone', 'Status', 'Source', 'Services', 'Appointment At', 'Payment Method', 'Total', 'Currency', 'Created At'],
            $rows
        );
    }
}
