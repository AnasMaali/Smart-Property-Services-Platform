<?php

namespace App\Http\Controllers\Api\V1\Admin\Technician;

use App\Actions\Admin\Technician\AdminAssignTechnicianAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignTechnicianRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AssignTechnicianController extends Controller
{
    public function __invoke(AssignTechnicianRequest $request, AdminAssignTechnicianAction $action, string $bookingItem): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle(
            $request,
            $bookingItem,
            $request->string('technician_uuid')->toString(),
            $authUser,
            $request->string('internal_note')->toString() ?: null,
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
