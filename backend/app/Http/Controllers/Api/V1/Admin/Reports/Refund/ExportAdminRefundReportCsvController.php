<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports\Refund;

use App\Actions\Admin\Reports\AdminRefundReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GetAdminRefundReportRequest;
use App\Support\Admin\Reports\AdminReportCsv;
use App\Support\Admin\Reports\AdminReportFilename;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportAdminRefundReportCsvController extends Controller
{
    public function __invoke(GetAdminRefundReportRequest $request, AdminRefundReportAction $action): StreamedResponse|JsonResponse
    {
        $filters = array_filter([
            'range' => $request->string('range')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $export = $action->exportRows($filters);

        if ($export === null) {
            return response()->json(['success' => false, 'message' => 'Invalid Refund Report filters.'], 422);
        }

        $rows = (function () use ($export) {
            foreach ($export['rows'] as $row) {
                yield [
                    $row['requested_at'], $row['booking_number'], $row['original_payment_reference'],
                    $row['amount'], $row['currency_code'], $row['status'], $row['reason'],
                    $row['succeeded_at'], $row['failed_at'],
                ];
            }
        })();

        return AdminReportCsv::stream(
            AdminReportFilename::build('refunds', 'csv', Carbon::parse($export['range']['from']), Carbon::parse($export['range']['to'])),
            ['Requested At', 'Booking Number', 'Original Payment Reference', 'Amount', 'Currency', 'Status', 'Reason', 'Succeeded At', 'Failed At'],
            $rows
        );
    }
}
