<?php

namespace App\Http\Controllers\Api\V1\Admin\AppointmentSchedule;

use App\Actions\Admin\AppointmentSchedule\AdminGenerateAppointmentScheduleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AppointmentSchedule\GenerateAdminAppointmentScheduleRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class GenerateAdminAppointmentScheduleController extends Controller
{
    public function __invoke(GenerateAdminAppointmentScheduleRequest $request, AdminGenerateAppointmentScheduleAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle(
            $request,
            $authUser,
            $request->string('from')->toString(),
            $request->string('to')->toString(),
            $request->filled('booking_capacity') ? $request->integer('booking_capacity') : null,
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
