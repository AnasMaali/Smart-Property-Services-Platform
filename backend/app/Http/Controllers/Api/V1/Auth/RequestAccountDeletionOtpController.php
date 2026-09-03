<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\IssueAccountDeletionOtpAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RequestAccountDeletionOtpController extends Controller
{
    public function __invoke(Request $request, IssueAccountDeletionOtpAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($authUser->id);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['success'] ? 200 : 422);
    }
}
