<?php

namespace App\Http\Controllers\Api\V1\Admin\Booking;

use App\Actions\Admin\Booking\AdminUpdateDraftRepairQuoteAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAdminRepairQuoteRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UpdateAdminRepairQuoteDraftController extends Controller
{
    public function __invoke(CreateAdminRepairQuoteRequest $request, AdminUpdateDraftRepairQuoteAction $action, string $quote): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $authUser, $quote, $request->string('quoted_amount')->toString());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
