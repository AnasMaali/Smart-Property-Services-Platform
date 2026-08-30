<?php

namespace App\Http\Controllers\Api\V1\Admin\Booking;

use App\Actions\Admin\Booking\AdminListAppointmentSlotsAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class ListAdminAppointmentSlotsController extends Controller
{
    public function __invoke(AdminListAppointmentSlotsAction $action): JsonResponse
    {
        $result = $action->handle();

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
