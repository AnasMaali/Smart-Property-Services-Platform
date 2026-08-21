<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\GetAccountDeletionStatusAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetAccountDeletionStatusController extends Controller
{
    public function __invoke(Request $request, GetAccountDeletionStatusAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($authUser->id);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
