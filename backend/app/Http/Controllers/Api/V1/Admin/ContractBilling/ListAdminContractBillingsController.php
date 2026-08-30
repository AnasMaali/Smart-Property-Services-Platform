<?php

namespace App\Http\Controllers\Api\V1\Admin\ContractBilling;

use App\Actions\Admin\ContractBilling\AdminListContractBillingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminContractBillingsRequest;
use Illuminate\Http\JsonResponse;

class ListAdminContractBillingsController extends Controller
{
    public function __invoke(ListAdminContractBillingsRequest $request, AdminListContractBillingsAction $action): JsonResponse
    {
        $filters = array_filter([
            'status' => $request->string('status')->toString() ?: null,
            'contract_number' => $request->string('contract_number')->toString() ?: null,
            'customer_uuid' => $request->string('customer_uuid')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $result = $action->handle(
            $filters,
            (int) ($request->integer('page') ?: 1),
            (int) ($request->integer('per_page') ?: AdminListContractBillingsAction::DEFAULT_PER_PAGE),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
