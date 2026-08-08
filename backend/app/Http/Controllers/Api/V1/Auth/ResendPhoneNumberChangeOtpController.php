<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ResendPhoneNumberChangeOtpAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResendPhoneNumberChangeOtpRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ResendPhoneNumberChangeOtpController extends Controller
{
    public function __invoke(ResendPhoneNumberChangeOtpRequest $request, ResendPhoneNumberChangeOtpAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($authUser->id, $request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['success'] ? 200 : 422);
    }
}
