<?php

namespace App\Http\Controllers\Api\V1\ServiceCatalog;

use App\Actions\ServiceCatalog\ListCategoryServicesAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ListCategoryServicesController extends Controller
{
    public function __invoke(string $category, ListCategoryServicesAction $action): JsonResponse
    {
        if (! ctype_digit($category)) {
            return $this->notFound();
        }

        $result = $action->handle((int) $category);

        if ($result === null) {
            return $this->notFound();
        }

        return response()->json([
            'success' => true,
            'message' => 'Category services retrieved successfully.',
            'data' => $result,
        ], 200);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Service category not found.',
        ], 404);
    }
}
