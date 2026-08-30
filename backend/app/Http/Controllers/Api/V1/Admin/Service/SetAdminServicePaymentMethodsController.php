<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminSetServicePaymentMethodsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetAdminServicePaymentMethodsRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SetAdminServicePaymentMethodsController extends Controller
{
    public function __invoke(SetAdminServicePaymentMethodsRequest $request, AdminSetServicePaymentMethodsAction $action, string $service): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $authUser, $service, $request->input('payment_methods'));

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
