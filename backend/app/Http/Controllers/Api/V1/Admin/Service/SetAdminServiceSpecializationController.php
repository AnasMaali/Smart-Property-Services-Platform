<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminSetServiceSpecializationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetAdminServiceSpecializationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SetAdminServiceSpecializationController extends Controller
{
    public function __invoke(SetAdminServiceSpecializationRequest $request, AdminSetServiceSpecializationAction $action, string $service): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle(
            $request,
            $service,
            $authUser,
            $request->integer('specialization_id'),
            $request->boolean('is_primary'),
            $request->boolean('is_active'),
            $request->integer('display_order', 0),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
