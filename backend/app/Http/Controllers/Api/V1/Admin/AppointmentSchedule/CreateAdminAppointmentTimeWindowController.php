<?php

namespace App\Http\Controllers\Api\V1\Admin\AppointmentSchedule;

use App\Actions\Admin\AppointmentSchedule\AdminCreateAppointmentTimeWindowAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AppointmentSchedule\CreateAdminAppointmentTimeWindowRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CreateAdminAppointmentTimeWindowController extends Controller
{
    public function __invoke(CreateAdminAppointmentTimeWindowRequest $request, AdminCreateAppointmentTimeWindowAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $authUser, [
            'code' => $request->string('code')->toString(),
            'name' => $request->string('name')->toString(),
            'description' => $request->input('description'),
            'start_time' => $request->string('start_time')->toString(),
            'end_time' => $request->string('end_time')->toString(),
            'display_order' => $request->integer('display_order', 0),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
