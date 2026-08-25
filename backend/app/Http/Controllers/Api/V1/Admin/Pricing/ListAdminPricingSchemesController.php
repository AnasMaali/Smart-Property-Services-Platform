<?php

namespace App\Http\Controllers\Api\V1\Admin\Pricing;

use App\Actions\Admin\Pricing\AdminListPricingSchemesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminPricingSchemesRequest;
use Illuminate\Http\JsonResponse;

class ListAdminPricingSchemesController extends Controller
{
    public function __invoke(ListAdminPricingSchemesRequest $request, AdminListPricingSchemesAction $action): JsonResponse
    {
        $filters = array_filter([
            'service_uuid' => $request->string('service_uuid')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'currency' => $request->string('currency')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $result = $action->handle(
            $filters,
            (int) ($request->integer('page') ?: 1),
            (int) ($request->integer('per_page') ?: AdminListPricingSchemesAction::DEFAULT_PER_PAGE),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
