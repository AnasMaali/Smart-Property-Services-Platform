<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\DeleteAccountAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DeleteAccountRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DeleteAccountController extends Controller
{
    public function __invoke(DeleteAccountRequest $request, DeleteAccountAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($authUser->id, $request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
