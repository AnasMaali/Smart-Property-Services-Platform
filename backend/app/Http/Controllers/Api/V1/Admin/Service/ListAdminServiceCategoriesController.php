<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminListServiceCategoriesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminServiceCategoriesRequest;
use Illuminate\Http\JsonResponse;

class ListAdminServiceCategoriesController extends Controller
{
    public function __invoke(ListAdminServiceCategoriesRequest $request, AdminListServiceCategoriesAction $action): JsonResponse
    {
        $filters = [];

        if ($request->has('is_active')) {
            $filters['is_active'] = $request->boolean('is_active');
        }

        $result = $action->handle($filters);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
