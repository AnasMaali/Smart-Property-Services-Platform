<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports\PayOnSite;

use App\Actions\Admin\Reports\AdminPayOnSiteReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GetAdminPayOnSiteReportRequest;
use App\Support\Admin\Reports\AdminReportCsv;
use App\Support\Admin\Reports\AdminReportFilename;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportAdminPayOnSiteReportCsvController extends Controller
{
    public function __invoke(GetAdminPayOnSiteReportRequest $request, AdminPayOnSiteReportAction $action): StreamedResponse|JsonResponse
    {
        $filters = array_filter([
            'range' => $request->string('range')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $export = $action->exportRows($filters);

        if ($export === null) {
            return response()->json(['success' => false, 'message' => 'Invalid Pay-on-Site Report filters.'], 422);
        }

        $rows = (function () use ($export) {
            foreach ($export['rows'] as $row) {
                yield [
                    $row['booking_number'], $row['customer_name'], $row['customer_phone'],
                    $row['amount_due'], $row['amount_collected'], $row['status'],
                    $row['collected_at'], $row['collected_by'], $row['created_at'],
                ];
            }
        })();

        return AdminReportCsv::stream(
            AdminReportFilename::build('pay-on-site', 'csv', Carbon::parse($export['range']['from']), Carbon::parse($export['range']['to'])),
            ['Booking Number', 'Customer Name', 'Customer Phone', 'Amount Due', 'Amount Collected', 'Status', 'Collected At', 'Collected By', 'Created At'],
            $rows
        );
    }
}
