<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminServiceCheckpointAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAdminServiceCheckpointRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CreateAdminServiceCheckpointController extends Controller
{
    public function __invoke(CreateAdminServiceCheckpointRequest $request, AdminServiceCheckpointAction $action, string $group): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->create($request, $authUser, $group, [
            'name' => $request->string('name')->toString(),
            'description' => $request->input('description'),
            'action_type_code' => $request->string('action_type_code')->toString(),
            'display_order' => $request->integer('display_order', 0),
        ]);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
