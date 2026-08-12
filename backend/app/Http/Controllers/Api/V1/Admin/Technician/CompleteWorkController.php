<?php

namespace App\Http\Controllers\Api\V1\Admin\Technician;

use App\Actions\Admin\Technician\AdminCompleteTechnicianJobAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompleteWorkRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CompleteWorkController extends Controller
{
    public function __invoke(CompleteWorkRequest $request, AdminCompleteTechnicianJobAction $action, string $bookingItem): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle(
            $request,
            $bookingItem,
            $request->string('technician_uuid')->toString(),
            $authUser,
            $request->string('reason')->toString() ?: null,
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
