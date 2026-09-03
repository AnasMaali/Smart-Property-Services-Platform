<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Actions\Booking\CreateBookingRatingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CreateBookingRatingRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CreateBookingRatingController extends Controller
{
    public function __invoke(
        CreateBookingRatingRequest $request,
        CreateBookingRatingAction $action,
        string $booking
    ): JsonResponse {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle(
            customerUserUuid: $authUser->id,
            bookingUuid: $booking,
            data: $request->validated(),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
