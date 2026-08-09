<?php

namespace App\Http\Controllers\Api\V1\Cart;

use App\Actions\Cart\AddCartItemAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddCartItemRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AddCartItemController extends Controller
{
    public function __invoke(AddCartItemRequest $request, AddCartItemAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($authUser->id, $request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
