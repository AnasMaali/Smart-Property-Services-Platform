<?php

namespace App\Http\Controllers\Api\V1\Admin\Booking;

use App\Actions\Admin\Booking\AdminGetBookingAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetAdminBookingController extends Controller
{
    public function __invoke(Request $request, AdminGetBookingAction $action, string $booking): JsonResponse
    {
        $result = $action->handle($booking);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
