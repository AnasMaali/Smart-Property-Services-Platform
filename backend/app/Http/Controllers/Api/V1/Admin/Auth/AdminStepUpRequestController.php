<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Actions\Auth\AdminStepUpRequestAction;
use App\Http\Controllers\Controller;
use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminStepUpRequestController extends Controller
{
    public function __invoke(Request $request, AdminStepUpRequestAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        /** @var AuthSession $authSession */
        $authSession = $request->attributes->get('auth_session');

        $result = $action->handle($authUser, $authSession);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['success'] ? 200 : 422);
    }
}
