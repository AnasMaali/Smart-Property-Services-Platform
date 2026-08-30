<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminListPaymentMethodTypesAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ListAdminPaymentMethodTypesController extends Controller
{
    public function __invoke(AdminListPaymentMethodTypesAction $action): JsonResponse
    {
        $result = $action->handle();

        return response()->json(['success' => $result['success'], 'message' => $result['message'], 'data' => $result['data']], $result['status']);
    }
}
