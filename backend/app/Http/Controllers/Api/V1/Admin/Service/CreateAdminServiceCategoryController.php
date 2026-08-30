<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminCreateServiceCategoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAdminServiceCategoryRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CreateAdminServiceCategoryController extends Controller
{
    public function __invoke(CreateAdminServiceCategoryRequest $request, AdminCreateServiceCategoryAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $authUser, [
            'code' => $request->string('code')->toString(),
            'name' => $request->string('name')->toString(),
            'description' => $request->input('description'),
            'display_order' => $request->integer('display_order', 0),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
