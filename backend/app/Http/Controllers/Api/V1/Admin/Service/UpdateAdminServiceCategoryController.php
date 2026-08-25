<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminUpdateServiceCategoryMetadataAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminServiceCategoryRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UpdateAdminServiceCategoryController extends Controller
{
    public function __invoke(UpdateAdminServiceCategoryRequest $request, AdminUpdateServiceCategoryMetadataAction $action, string $category): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $category, $authUser, [
            'name' => $request->string('name')->toString(),
            'description' => $request->input('description'),
            'display_order' => $request->integer('display_order'),
        ]);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
