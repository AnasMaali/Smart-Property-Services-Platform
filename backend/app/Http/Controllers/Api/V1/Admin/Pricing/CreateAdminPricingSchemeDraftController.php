<?php

namespace App\Http\Controllers\Api\V1\Admin\Pricing;

use App\Actions\Admin\Pricing\AdminCreatePricingSchemeDraftAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAdminPricingSchemeDraftRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CreateAdminPricingSchemeDraftController extends Controller
{
    public function __invoke(CreateAdminPricingSchemeDraftRequest $request, AdminCreatePricingSchemeDraftAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle(
            $request,
            $authUser,
            $request->string('service_uuid')->toString(),
            strtoupper($request->string('currency_code')->toString()),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
