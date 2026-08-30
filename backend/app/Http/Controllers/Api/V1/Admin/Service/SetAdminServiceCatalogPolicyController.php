<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminSetServiceCatalogPolicyAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetAdminServiceCatalogPolicyRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SetAdminServiceCatalogPolicyController extends Controller
{
    public function __invoke(SetAdminServiceCatalogPolicyRequest $request, AdminSetServiceCatalogPolicyAction $action, string $service): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $authUser, $service, [
            'is_featured' => $request->boolean('is_featured'),
            'estimated_duration_minutes' => $request->input('estimated_duration_minutes'),
            'min_quantity' => $request->integer('min_quantity'),
            'max_quantity' => $request->integer('max_quantity'),
        ]);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
