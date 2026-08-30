<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminGetServiceCategoryAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class GetAdminServiceCategoryController extends Controller
{
    public function __invoke(AdminGetServiceCategoryAction $action, string $category): JsonResponse
    {
        $result = $action->handle($category);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
