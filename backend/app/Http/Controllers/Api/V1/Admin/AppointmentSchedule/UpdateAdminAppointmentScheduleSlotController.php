<?php

namespace App\Http\Controllers\Api\V1\Admin\AppointmentSchedule;

use App\Actions\Admin\AppointmentSchedule\AdminUpdateAppointmentScheduleSlotAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AppointmentSchedule\UpdateAdminAppointmentScheduleSlotRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UpdateAdminAppointmentScheduleSlotController extends Controller
{
    public function __invoke(UpdateAdminAppointmentScheduleSlotRequest $request, AdminUpdateAppointmentScheduleSlotAction $action, string $slot): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $slot, $authUser, [
            'booking_capacity' => $request->integer('booking_capacity'),
            'internal_note' => $request->input('internal_note'),
        ]);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
