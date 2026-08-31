<?php

namespace App\Http\Controllers\Api\V1\Admin\Financial;

use App\Actions\Admin\Financial\AdminGetFinancialDashboardAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GetAdminFinancialDashboardRequest;
use Illuminate\Http\JsonResponse;

class GetAdminFinancialDashboardController extends Controller
{
    public function __invoke(GetAdminFinancialDashboardRequest $request, AdminGetFinancialDashboardAction $action): JsonResponse
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
            'data' => $result['data'],
        ], $result['status']);
    }
}
