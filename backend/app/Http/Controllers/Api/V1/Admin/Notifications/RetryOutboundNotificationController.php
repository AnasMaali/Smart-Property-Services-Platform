<?php

namespace App\Http\Controllers\Api\V1\Admin\Notifications;

use App\Actions\Admin\Notifications\AdminRetryTechnicianNotificationAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BLUE V1 Phase B21 - manual Admin retry for one stuck/failed Technician
 * WhatsApp notification. Reuses the `technicians.assign` capability (see
 * routes/api.php) - this is an operational extension of the assign/
 * reassign flow, never a new, broader notification-mutation surface.
 */
final class RetryOutboundNotificationController extends Controller
{
    public function __invoke(Request $request, AdminRetryTechnicianNotificationAction $action, string $notification): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($notification, $authUser->id);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
