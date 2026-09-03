<?php

namespace App\Http\Controllers\Api\V1\Support;

use App\Actions\Support\SendCustomerSupportMessageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\SendCustomerSupportMessageRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SendCustomerSupportMessageController extends Controller
{
    public function __invoke(
        SendCustomerSupportMessageRequest $request,
        SendCustomerSupportMessageAction $action,
        string $supportRequest
    ): JsonResponse {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');
        $result = $action->handle($authUser->id, $supportRequest, $request->validated()['message']);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
