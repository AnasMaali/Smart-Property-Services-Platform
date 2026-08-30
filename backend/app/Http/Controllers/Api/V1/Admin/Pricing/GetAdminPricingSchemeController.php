<?php

namespace App\Http\Controllers\Api\V1\Admin\Pricing;

use App\Actions\Admin\Pricing\AdminGetPricingSchemeAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class GetAdminPricingSchemeController extends Controller
{
    public function __invoke(AdminGetPricingSchemeAction $action, string $pricingScheme): JsonResponse
    {
        $result = $action->handle($pricingScheme);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
