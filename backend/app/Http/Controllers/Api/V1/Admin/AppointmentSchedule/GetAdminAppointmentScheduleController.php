<?php

namespace App\Http\Controllers\Api\V1\Admin\AppointmentSchedule;

use App\Actions\Admin\AppointmentSchedule\AdminGetAppointmentScheduleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AppointmentSchedule\GetAdminAppointmentScheduleRequest;
use Illuminate\Http\JsonResponse;

class GetAdminAppointmentScheduleController extends Controller
{
    public function __invoke(GetAdminAppointmentScheduleRequest $request, AdminGetAppointmentScheduleAction $action): JsonResponse
    {
        $result = $action->handle($request->string('date')->toString());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
