<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminSetServiceCapabilitiesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetAdminServiceCapabilitiesRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SetAdminServiceCapabilitiesController extends Controller
{
    public function __invoke(SetAdminServiceCapabilitiesRequest $request, AdminSetServiceCapabilitiesAction $action, string $service): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $authUser, $service, $request->input('capabilities'));

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
