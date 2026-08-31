<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports\Payment;

use App\Actions\Admin\Reports\AdminPaymentReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GetAdminPaymentReportRequest;
use App\Support\Admin\Reports\AdminReportCsv;
use App\Support\Admin\Reports\AdminReportFilename;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportAdminPaymentReportCsvController extends Controller
{
    public function __invoke(GetAdminPaymentReportRequest $request, AdminPaymentReportAction $action): StreamedResponse|JsonResponse
    {
        $filters = array_filter([
            'range' => $request->string('range')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'payment_method' => $request->string('payment_method')->toString() ?: null,
            'booking_uuid' => $request->string('booking_uuid')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $export = $action->exportRows($filters);

        if ($export === null) {
            return response()->json(['success' => false, 'message' => 'Invalid Payment Report filters.'], 422);
        }

        $rows = (function () use ($export) {
            foreach ($export['rows'] as $row) {
                yield [
                    $row['created_at'], $row['booking_number'], $row['customer_name'], $row['customer_phone'],
                    $row['payment_method'], $row['amount'], $row['currency_code'], $row['status'], $row['provider_reference'],
                ];
            }
        })();

        return AdminReportCsv::stream(
            AdminReportFilename::build('payments', 'csv', Carbon::parse($export['range']['from']), Carbon::parse($export['range']['to'])),
            ['Date', 'Booking Number', 'Customer Name', 'Customer Phone', 'Payment Method', 'Amount', 'Currency', 'Status', 'Provider Reference'],
            $rows
        );
    }
}
