<?php

namespace App\Http\Controllers\Api\V1\Admin\Pricing;

use App\Actions\Admin\Pricing\AdminSetServiceCurrentPriceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetAdminServiceCurrentPriceRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SetAdminServiceCurrentPriceController extends Controller
{
    public function __invoke(SetAdminServiceCurrentPriceRequest $request, AdminSetServiceCurrentPriceAction $action, string $service): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $authUser, $service, (string) $request->input('current_price'));

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
