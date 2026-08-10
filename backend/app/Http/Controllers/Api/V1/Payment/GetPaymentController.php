<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Actions\Payment\GetPaymentAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetPaymentController extends Controller
{
    public function __invoke(Request $request, GetPaymentAction $action, string $payment): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($authUser->id, $payment);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
