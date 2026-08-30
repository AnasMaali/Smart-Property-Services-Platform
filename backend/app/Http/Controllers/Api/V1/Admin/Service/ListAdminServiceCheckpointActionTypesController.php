<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminListServiceCheckpointActionTypesAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ListAdminServiceCheckpointActionTypesController extends Controller
{
    public function __invoke(AdminListServiceCheckpointActionTypesAction $action): JsonResponse
    {
        $result = $action->handle();

        return response()->json(['success' => $result['success'], 'message' => $result['message'], 'data' => $result['data']], $result['status']);
    }
}
