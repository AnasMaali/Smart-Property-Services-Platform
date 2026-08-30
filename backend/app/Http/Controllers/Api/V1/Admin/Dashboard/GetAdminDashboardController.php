<?php

namespace App\Http\Controllers\Api\V1\Admin\Dashboard;

use App\Actions\Admin\Dashboard\AdminGetDashboardAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class GetAdminDashboardController extends Controller
{
    public function __invoke(AdminGetDashboardAction $action): JsonResponse
    {
        $result = $action->handle();

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
