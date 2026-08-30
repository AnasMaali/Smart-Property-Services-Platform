<?php

namespace App\Http\Controllers\Api\V1\Admin\Technician;

use App\Actions\Admin\Technician\AdminGetTechnicianAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class GetAdminTechnicianController extends Controller
{
    public function __invoke(AdminGetTechnicianAction $action, string $technician): JsonResponse
    {
        $result = $action->handle($technician);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
