<?php

namespace App\Http\Controllers\Api\V1\Admin\Technician;

use App\Actions\Admin\Technician\AdminCreateTechnicianAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAdminTechnicianRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CreateAdminTechnicianController extends Controller
{
    public function __invoke(CreateAdminTechnicianRequest $request, AdminCreateTechnicianAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $authUser, [
            'employee_code' => $request->string('employee_code')->toString(),
            'full_name' => $request->string('full_name')->toString(),
            'phone_number' => $request->string('phone_number')->toString(),
            'email' => $request->input('email'),
            'is_phone_visible_to_customer' => $request->boolean('is_phone_visible_to_customer'),
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
