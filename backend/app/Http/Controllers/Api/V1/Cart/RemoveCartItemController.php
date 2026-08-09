<?php

namespace App\Http\Controllers\Api\V1\Cart;

use App\Actions\Cart\RemoveCartItemAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RemoveCartItemController extends Controller
{
    public function __invoke(Request $request, RemoveCartItemAction $action, string $item): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($authUser->id, $item);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
