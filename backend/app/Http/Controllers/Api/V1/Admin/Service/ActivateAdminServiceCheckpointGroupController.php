<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminServiceCheckpointGroupAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivateAdminServiceCheckpointGroupController extends Controller
{
    public function __invoke(Request $request, AdminServiceCheckpointGroupAction $action, string $group): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->setActive($request, $authUser, $group, true);

        return response()->json(['success' => $result['success'], 'message' => $result['message'], 'data' => $result['data']], $result['status']);
    }
}
