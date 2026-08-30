<?php

namespace App\Http\Controllers\Api\V1\Admin\Booking;

use App\Actions\Booking\PreviewBookingCancellationAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Admin cancellation-refund preview - reuses App\Actions\Booking\
 * PreviewBookingCancellationAction verbatim ($requireOwnerUuid = null, an
 * Admin may preview any customer's Booking), never a separate Admin
 * refund calculator. Gated by the same `bookings.cancel` capability as
 * the real Admin cancel endpoint, since this is read access to the exact
 * same operation.
 */
final class PreviewAdminBookingCancellationController extends Controller
{
    public function __invoke(PreviewBookingCancellationAction $action, string $booking): JsonResponse
    {
        $result = $action->handle(bookingUuid: $booking);

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
