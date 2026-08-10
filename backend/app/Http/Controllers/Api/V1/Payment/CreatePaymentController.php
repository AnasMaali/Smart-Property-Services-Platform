<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Actions\Payment\CreatePaymentAttemptAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\CreatePaymentRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CreatePaymentController extends Controller
{
    public function __invoke(CreatePaymentRequest $request, CreatePaymentAttemptAction $action): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            return response()->json([
                'success' => false,
                'message' => 'The Idempotency-Key header is required.',
                'errors' => ['The Idempotency-Key header is required.'],
            ], 422);
        }

        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($authUser->id, $idempotencyKey);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
