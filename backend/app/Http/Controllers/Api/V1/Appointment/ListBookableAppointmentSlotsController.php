<?php

namespace App\Http\Controllers\Api\V1\Appointment;

use App\Actions\Appointment\ListBookableAppointmentSlotsAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class ListBookableAppointmentSlotsController extends Controller
{
    public function __invoke(ListBookableAppointmentSlotsAction $action): JsonResponse
    {
        $result = $action->handle();

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
