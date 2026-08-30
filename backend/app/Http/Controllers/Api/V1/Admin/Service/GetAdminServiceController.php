<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminGetServiceAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class GetAdminServiceController extends Controller
{
    public function __invoke(AdminGetServiceAction $action, string $service): JsonResponse
    {
        $result = $action->handle($service);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
