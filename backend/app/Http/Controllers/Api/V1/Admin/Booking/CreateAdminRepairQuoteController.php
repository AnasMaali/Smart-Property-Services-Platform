<?php

namespace App\Http\Controllers\Api\V1\Admin\Booking;

use App\Actions\Admin\Booking\AdminCreateRepairQuoteAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAdminRepairQuoteRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CreateAdminRepairQuoteController extends Controller
{
    public function __invoke(CreateAdminRepairQuoteRequest $request, AdminCreateRepairQuoteAction $action, string $bookingItem): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $authUser, $bookingItem, $request->string('quoted_amount')->toString());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
