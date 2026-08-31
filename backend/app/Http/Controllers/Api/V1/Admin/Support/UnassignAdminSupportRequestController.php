<?php

namespace App\Http\Controllers\Api\V1\Admin\Support;

use App\Actions\Admin\Support\AdminUnassignSupportRequestAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnassignAdminSupportRequestController extends Controller
{
    public function __invoke(Request $request, AdminUnassignSupportRequestAction $action, string $supportRequest): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $supportRequest, $authUser);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
