<?php

namespace App\Http\Controllers\Api\V1\Admin\Booking;

use App\Actions\Admin\Booking\AdminRescheduleBookingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RescheduleAdminBookingRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class RescheduleAdminBookingController extends Controller
{
    public function __invoke(
        RescheduleAdminBookingRequest $request,
        string $booking,
        AdminRescheduleBookingAction $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->attributes->get('auth_user');

        $result = $action->handle(
            request: $request,
            actor: $actor,
            bookingUuid: $booking,
            newSlotUuid: $request->validated('appointment_slot_uuid'),
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
