<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports\Financial;

use App\Actions\Admin\Reports\AdminFinancialSummaryReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GetAdminFinancialSummaryReportRequest;
use Illuminate\Http\JsonResponse;

class GetAdminFinancialSummaryReportController extends Controller
{
    public function __invoke(GetAdminFinancialSummaryReportRequest $request, AdminFinancialSummaryReportAction $action): JsonResponse
    {
        $filters = array_filter([
            'range' => $request->string('range')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $result = $action->handle($filters);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'] ?? null,
        ], $result['status']);
    }
}
