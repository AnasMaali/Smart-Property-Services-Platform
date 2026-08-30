<?php

namespace App\Http\Controllers\Api\V1\Admin\Technician;

use App\Actions\Admin\Technician\AdminSetTechnicianSpecializationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetAdminTechnicianSpecializationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SetAdminTechnicianSpecializationController extends Controller
{
    public function __invoke(SetAdminTechnicianSpecializationRequest $request, AdminSetTechnicianSpecializationAction $action, string $technician): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle(
            $request,
            $technician,
            $authUser,
            $request->integer('specialization_id'),
            $request->boolean('is_primary'),
            $request->has('is_active') ? $request->boolean('is_active') : true,
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
