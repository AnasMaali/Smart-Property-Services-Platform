<?php

namespace App\Http\Controllers\Api\V1\Admin\Support;

use App\Actions\Admin\Support\AdminSendSupportMessageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendAdminSupportMessageRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SendAdminSupportMessageController extends Controller
{
    public function __invoke(SendAdminSupportMessageRequest $request, AdminSendSupportMessageAction $action, string $supportRequest): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $supportRequest, $authUser, $request->string('message_body')->toString());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
