<?php

namespace App\Http\Controllers\Api\V1\Admin\Technician;

use App\Actions\Admin\Technician\AdminReassignTechnicianAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReassignTechnicianRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ReassignTechnicianController extends Controller
{
    public function __invoke(ReassignTechnicianRequest $request, AdminReassignTechnicianAction $action, string $bookingItem): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle(
            $request,
            $bookingItem,
            $request->string('technician_uuid')->toString(),
            $authUser,
            $request->string('release_reason')->toString(),
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
