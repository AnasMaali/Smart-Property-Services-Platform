<?php

namespace App\Http\Controllers\Api\V1\ServiceCatalog;

use App\Actions\ServiceCatalog\ListServiceCategoriesAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ListServiceCategoriesController extends Controller
{
    public function __invoke(ListServiceCategoriesAction $action): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Service categories retrieved successfully.',
            'data' => [
                'service_categories' => $action->handle(),
            ],
        ], 200);
    }
}
