<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Actions\Booking\CreatePayOnSiteBookingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CreatePayOnSiteBookingRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CreatePayOnSiteBookingController extends Controller
{
    public function __invoke(CreatePayOnSiteBookingRequest $request, CreatePayOnSiteBookingAction $action): JsonResponse
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
