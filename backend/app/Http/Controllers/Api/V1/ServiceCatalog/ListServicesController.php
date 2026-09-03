<?php

namespace App\Http\Controllers\Api\V1\ServiceCatalog;

use App\Actions\ServiceCatalog\ListServicesAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListServicesController extends Controller
{
    public function __invoke(Request $request, ListServicesAction $action): JsonResponse
    {
        $query = $request->query('q');
        $category = $request->query('category');
        $capability = $request->query('capability');

        $categoryId = null;
        if (is_string($category) && ctype_digit($category)) {
            $categoryId = (int) $category;
        }

        $capabilityCode = null;
        if (is_string($capability) && preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', strtoupper(trim($capability))) === 1) {
            $capabilityCode = strtoupper(trim($capability));
        }

        $result = $action->handle(
            query: is_string($query) ? $query : null,
            categoryId: $categoryId,
            capabilityCode: $capabilityCode,
        );

        return response()->json([
            'success' => true,
            'message' => 'Services retrieved successfully.',
            'data' => $result,
        ], 200);
    }
}
