<?php

namespace App\Http\Controllers\Api\V1\ServiceCatalog;

use App\Actions\ServiceCatalog\PreviewServicePricingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceCatalog\PreviewServicePricingRequest;
use Illuminate\Http\JsonResponse;

class PreviewServicePricingController extends Controller
{
    public function __invoke(
        PreviewServicePricingRequest $request,
        PreviewServicePricingAction $action,
        string $service
    ): JsonResponse {
        $result = $action->handle($service, $request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
