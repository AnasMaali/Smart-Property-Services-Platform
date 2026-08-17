<?php

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Actions\Admin\Contract\AdminGetContractAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class GetAdminContractController extends Controller
{
    public function __invoke(AdminGetContractAction $action, string $contract): JsonResponse
    {
        $result = $action->handle($contract);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
