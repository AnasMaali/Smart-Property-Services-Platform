<?php

namespace App\Http\Controllers\Api\V1\Checkout;

use App\Actions\Checkout\SaveCheckoutLocationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\SaveCheckoutLocationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SaveCheckoutLocationController extends Controller
{
    public function __invoke(SaveCheckoutLocationRequest $request, SaveCheckoutLocationAction $action): JsonResponse
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
