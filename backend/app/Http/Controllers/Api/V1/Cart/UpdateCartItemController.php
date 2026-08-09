<?php

namespace App\Http\Controllers\Api\V1\Cart;

use App\Actions\Cart\UpdateCartItemAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UpdateCartItemController extends Controller
{
    public function __invoke(UpdateCartItemRequest $request, UpdateCartItemAction $action, string $item): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($authUser->id, $item, $request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
