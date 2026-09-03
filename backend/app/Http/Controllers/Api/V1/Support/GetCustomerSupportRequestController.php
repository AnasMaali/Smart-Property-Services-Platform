<?php

namespace App\Http\Controllers\Api\V1\Support;

use App\Actions\Support\GetCustomerSupportRequestAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetCustomerSupportRequestController extends Controller
{
    public function __invoke(Request $request, GetCustomerSupportRequestAction $action, string $supportRequest): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');
        $result = $action->handle($authUser->id, $supportRequest);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
