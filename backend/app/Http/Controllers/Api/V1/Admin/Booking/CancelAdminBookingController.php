<?php

namespace App\Http\Controllers\Api\V1\Admin\Booking;

use App\Actions\Admin\Booking\AdminCancelBookingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelAdminBookingRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class CancelAdminBookingController extends Controller
{
    public function __invoke(
        CancelAdminBookingRequest $request,
        string $booking,
        AdminCancelBookingAction $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->attributes->get('auth_user');

        $result = $action->handle(
            request: $request,
            actor: $actor,
            bookingUuid: $booking,
            reason: $request->validated('reason'),
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
