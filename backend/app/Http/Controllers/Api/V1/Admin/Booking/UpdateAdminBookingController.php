<?php

namespace App\Http\Controllers\Api\V1\Admin\Booking;

use App\Actions\Admin\Booking\AdminUpdateBookingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminBookingRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class UpdateAdminBookingController extends Controller
{
    public function __invoke(
        UpdateAdminBookingRequest $request,
        string $booking,
        AdminUpdateBookingAction $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->attributes->get('auth_user');

        $result = $action->handle(
            request: $request,
            actor: $actor,
            bookingUuid: $booking,
            input: $request->validated(),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(
                isset($result['errors'])
                    ? ['errors' => $result['errors']]
                    : []
            ),
        ], $result['status']);
    }
}
