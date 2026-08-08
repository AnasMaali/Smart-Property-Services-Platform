<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ChangePasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ChangePasswordController extends Controller
{
    public function __invoke(ChangePasswordRequest $request, ChangePasswordAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');
        /** @var AuthSession $authSession */
        $authSession = $request->attributes->get('auth_session');

        $result = $action->handle($authUser->id, $authSession->id, $request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['success'] ? 200 : 422);
    }
}
