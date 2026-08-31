<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports\Refund;

use App\Actions\Admin\Reports\AdminRefundReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GetAdminRefundReportRequest;
use Illuminate\Http\JsonResponse;

class GetAdminRefundReportController extends Controller
{
    public function __invoke(GetAdminRefundReportRequest $request, AdminRefundReportAction $action): JsonResponse
    {
        $filters = array_filter([
            'range' => $request->string('range')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $result = $action->screen($filters, (int) $request->integer('page', 1), (int) $request->integer('per_page', AdminRefundReportAction::DEFAULT_PER_PAGE));

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'] ?? null,
        ], $result['status']);
    }
}
