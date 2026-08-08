<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RequestPhoneNumberChangeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestPhoneNumberChangeRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class RequestPhoneNumberChangeController extends Controller
{
    public function __invoke(RequestPhoneNumberChangeRequest $request, RequestPhoneNumberChangeAction $action): JsonResponse
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
