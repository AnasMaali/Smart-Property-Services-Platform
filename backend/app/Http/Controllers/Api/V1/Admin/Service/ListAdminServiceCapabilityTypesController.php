<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminListServiceCapabilityTypesAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ListAdminServiceCapabilityTypesController extends Controller
{
    public function __invoke(AdminListServiceCapabilityTypesAction $action): JsonResponse
    {
        $result = $action->handle();

        return response()->json(['success' => $result['success'], 'message' => $result['message'], 'data' => $result['data']], $result['status']);
    }
}
