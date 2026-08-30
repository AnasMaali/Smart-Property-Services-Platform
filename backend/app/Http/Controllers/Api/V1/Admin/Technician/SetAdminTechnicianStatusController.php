<?php

namespace App\Http\Controllers\Api\V1\Admin\Technician;

use App\Actions\Admin\Technician\AdminSetTechnicianStatusAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetAdminTechnicianStatusRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SetAdminTechnicianStatusController extends Controller
{
    public function __invoke(SetAdminTechnicianStatusRequest $request, AdminSetTechnicianStatusAction $action, string $technician): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $technician, $authUser, $request->string('status')->toString());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
