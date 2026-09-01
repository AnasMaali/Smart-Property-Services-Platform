<?php

namespace App\Http\Controllers\Api\V1\Admin\AppointmentSchedule;

use App\Actions\Admin\AppointmentSchedule\AdminDeactivateAppointmentScheduleSlotAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeactivateAdminAppointmentScheduleSlotController extends Controller
{
    public function __invoke(Request $request, AdminDeactivateAppointmentScheduleSlotAction $action, string $slot): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $slot, $authUser);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
