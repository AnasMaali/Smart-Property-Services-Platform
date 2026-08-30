<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminChangeServiceCategoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeAdminServiceCategoryRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ChangeAdminServiceCategoryController extends Controller
{
    public function __invoke(ChangeAdminServiceCategoryRequest $request, AdminChangeServiceCategoryAction $action, string $service): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $service, $authUser, $request->integer('category_id'));

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
