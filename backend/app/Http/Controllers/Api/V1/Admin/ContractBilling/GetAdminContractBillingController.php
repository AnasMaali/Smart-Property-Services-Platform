<?php

namespace App\Http\Controllers\Api\V1\Admin\ContractBilling;

use App\Actions\Admin\ContractBilling\AdminGetContractBillingAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class GetAdminContractBillingController extends Controller
{
    public function __invoke(AdminGetContractBillingAction $action, string $billing): JsonResponse
    {
        $result = $action->handle($billing);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
