<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminSetServiceInspectionQuotePolicyAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetAdminServiceInspectionQuotePolicyRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SetAdminServiceInspectionQuotePolicyController extends Controller
{
    public function __invoke(SetAdminServiceInspectionQuotePolicyRequest $request, AdminSetServiceInspectionQuotePolicyAction $action, string $service): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $authUser, $service, (bool) $request->boolean('enabled'));

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
