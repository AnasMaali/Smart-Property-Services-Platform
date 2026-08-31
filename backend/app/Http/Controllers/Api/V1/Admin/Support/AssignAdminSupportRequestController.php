<?php

namespace App\Http\Controllers\Api\V1\Admin\Support;

use App\Actions\Admin\Support\AdminAssignSupportRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignAdminSupportRequestRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AssignAdminSupportRequestController extends Controller
{
    public function __invoke(AssignAdminSupportRequestRequest $request, AdminAssignSupportRequestAction $action, string $supportRequest): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $supportRequest, $authUser, $request->string('admin_uuid')->toString());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
