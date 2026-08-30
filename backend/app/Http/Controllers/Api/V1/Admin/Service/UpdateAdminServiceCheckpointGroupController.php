<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminServiceCheckpointGroupAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminServiceCheckpointGroupRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UpdateAdminServiceCheckpointGroupController extends Controller
{
    public function __invoke(UpdateAdminServiceCheckpointGroupRequest $request, AdminServiceCheckpointGroupAction $action, string $group): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->update($request, $authUser, $group, [
            'name' => $request->string('name')->toString(),
            'description' => $request->input('description'),
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
