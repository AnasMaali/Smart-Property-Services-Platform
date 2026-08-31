<?php

namespace App\Http\Controllers\Api\V1\Admin\Pricing;

use App\Actions\Admin\Pricing\AdminPreviewPricingSchemeVersionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PreviewAdminPricingSchemeVersionRequest;
use Illuminate\Http\JsonResponse;

class PreviewAdminPricingSchemeVersionController extends Controller
{
    public function __invoke(PreviewAdminPricingSchemeVersionRequest $request, AdminPreviewPricingSchemeVersionAction $action, string $pricingScheme): JsonResponse
    {
        $result = $action->handle(
            $pricingScheme,
            $request->integer('quantity', 1),
            $request->input('options', []),
            $request->input('context', []),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
