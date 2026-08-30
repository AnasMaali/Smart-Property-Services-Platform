<?php

namespace App\Http\Controllers\Api\V1\Admin\Technician;

use App\Actions\Admin\Technician\AdminUpdateTechnicianAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminTechnicianRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UpdateAdminTechnicianController extends Controller
{
    public function __invoke(UpdateAdminTechnicianRequest $request, AdminUpdateTechnicianAction $action, string $technician): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $data = [];

        foreach (['full_name', 'phone_number', 'email', 'internal_note'] as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        if ($request->has('is_phone_visible_to_customer')) {
            $data['is_phone_visible_to_customer'] = $request->boolean('is_phone_visible_to_customer');
        }

        $result = $action->handle($request, $technician, $authUser, $data);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
