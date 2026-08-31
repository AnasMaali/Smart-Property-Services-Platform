<?php

namespace App\Http\Controllers\Api\V1\Admin\Support;

use App\Actions\Admin\Support\AdminUpdateSupportRequestStatusAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminSupportRequestStatusRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UpdateAdminSupportRequestStatusController extends Controller
{
    public function __invoke(UpdateAdminSupportRequestStatusRequest $request, AdminUpdateSupportRequestStatusAction $action, string $supportRequest): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $supportRequest, $authUser, $request->string('status')->toString());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
