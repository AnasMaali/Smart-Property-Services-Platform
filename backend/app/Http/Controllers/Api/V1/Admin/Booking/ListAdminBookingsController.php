<?php

namespace App\Http\Controllers\Api\V1\Admin\Booking;

use App\Actions\Admin\Booking\AdminListBookingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminBookingsRequest;
use Illuminate\Http\JsonResponse;

class ListAdminBookingsController extends Controller
{
    public function __invoke(ListAdminBookingsRequest $request, AdminListBookingsAction $action): JsonResponse
    {
        $filters = array_filter([
            'status' => $request->string('status')->toString() ?: null,
            'booking_number' => $request->string('booking_number')->toString() ?: null,
            'customer_uuid' => $request->string('customer_uuid')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'appointment_date' => $request->string('appointment_date')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $result = $action->handle(
            $filters,
            (int) ($request->integer('page') ?: 1),
            (int) ($request->integer('per_page') ?: AdminListBookingsAction::DEFAULT_PER_PAGE),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
