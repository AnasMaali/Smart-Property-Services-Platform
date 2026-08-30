<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminServiceCheckpointAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminServiceCheckpointRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UpdateAdminServiceCheckpointController extends Controller
{
    public function __invoke(UpdateAdminServiceCheckpointRequest $request, AdminServiceCheckpointAction $action, string $checkpoint): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->update($request, $authUser, $checkpoint, [
            'name' => $request->string('name')->toString(),
            'description' => $request->input('description'),
            'action_type_code' => $request->string('action_type_code')->toString(),
            'display_order' => $request->integer('display_order', 0),
            'group_uuid' => $request->input('group_uuid'),
        ]);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
