<?php

namespace App\Http\Controllers\Api\V1\Checkout;

use App\Actions\Checkout\CreateAppointmentHoldAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\CreateAppointmentHoldRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CreateAppointmentHoldController extends Controller
{
    public function __invoke(CreateAppointmentHoldRequest $request, CreateAppointmentHoldAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($authUser->id, $request->validated('appointment_slot_uuid'));

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
