<?php

namespace App\Http\Controllers\Api\V1\Admin\Booking;

use App\Actions\Admin\Booking\AdminForceCompleteBookingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ForceCompleteAdminBookingRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class ForceCompleteAdminBookingController extends Controller
{
    public function __invoke(
        ForceCompleteAdminBookingRequest $request,
        string $booking,
        AdminForceCompleteBookingAction $action,
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
