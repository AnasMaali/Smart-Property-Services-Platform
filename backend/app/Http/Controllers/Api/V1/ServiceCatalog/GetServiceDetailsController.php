<?php

namespace App\Http\Controllers\Api\V1\ServiceCatalog;

use App\Actions\ServiceCatalog\GetServiceDetailsAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class GetServiceDetailsController extends Controller
{
    public function __invoke(string $service, GetServiceDetailsAction $action): JsonResponse
    {
        $result = $action->handle($service);

        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Service details retrieved successfully.',
            'data' => $result,
        ], 200);
    }
}
