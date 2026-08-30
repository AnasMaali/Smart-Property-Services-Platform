<?php

namespace App\Http\Controllers\Api\V1\Admin\Pricing;

use App\Actions\Admin\Pricing\AdminPreviewServicePricingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PreviewAdminServicePricingRequest;
use Illuminate\Http\JsonResponse;

class PreviewAdminServicePricingController extends Controller
{
    public function __invoke(PreviewAdminServicePricingRequest $request, AdminPreviewServicePricingAction $action, string $service): JsonResponse
    {
        $result = $action->handle($service, $request->integer('quantity', 1), $request->input('options', []));

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
