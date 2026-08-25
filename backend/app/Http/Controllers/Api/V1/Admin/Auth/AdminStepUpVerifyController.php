<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Actions\Auth\AdminStepUpVerifyAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AdminStepUpVerifyRequest;
use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminStepUpVerifyController extends Controller
{
    public function __invoke(AdminStepUpVerifyRequest $request, AdminStepUpVerifyAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        /** @var AuthSession $authSession */
        $authSession = $request->attributes->get('auth_session');

        $result = $action->handle($authUser, $authSession, $request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['success'] ? 200 : 422);
    }
}
