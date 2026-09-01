<?php

namespace App\Http\Controllers\Api\V1\Admin\AppointmentSchedule;

use App\Actions\Admin\AppointmentSchedule\AdminUpdateAppointmentTimeWindowAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AppointmentSchedule\UpdateAdminAppointmentTimeWindowRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UpdateAdminAppointmentTimeWindowController extends Controller
{
    public function __invoke(UpdateAdminAppointmentTimeWindowRequest $request, AdminUpdateAppointmentTimeWindowAction $action, string $window): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $window, $authUser, [
            'name' => $request->string('name')->toString(),
            'description' => $request->input('description'),
            'start_time' => $request->string('start_time')->toString(),
            'end_time' => $request->string('end_time')->toString(),
            'display_order' => $request->integer('display_order', 0),
        ]);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
