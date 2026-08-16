<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Actions\Booking\CancelBookingAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CancelBookingController extends Controller
{
    public function __invoke(
        Request $request,
        CancelBookingAction $action,
        string $booking
    ): JsonResponse {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle(
            $authUser->id,
            $booking
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors'])
                ? ['errors' => $result['errors']]
                : []),
        ], $result['status']);
    }
}