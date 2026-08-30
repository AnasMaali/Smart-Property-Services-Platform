<?php

namespace App\Http\Controllers\Api\V1\Admin\Audit;

use App\Actions\Admin\Audit\AdminGetAuditLogAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class GetAdminAuditLogController extends Controller
{
    public function __invoke(AdminGetAuditLogAction $action, string $auditLog): JsonResponse
    {
        $result = $action->handle($auditLog);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
