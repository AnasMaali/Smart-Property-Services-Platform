<?php

namespace App\Http\Controllers\Api\V1\Admin\AppointmentSchedule;

use App\Actions\Admin\AppointmentSchedule\AdminGetAppointmentScheduleSlotAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class GetAdminAppointmentScheduleSlotController extends Controller
{
    public function __invoke(AdminGetAppointmentScheduleSlotAction $action, string $slot): JsonResponse
    {
        $result = $action->handle($slot);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
