<?php

namespace App\Http\Controllers\Api\V1\Admin\Pricing;

use App\Actions\Admin\Pricing\AdminRetirePricingSchemeVersionAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetireAdminPricingSchemeVersionController extends Controller
{
    public function __invoke(Request $request, AdminRetirePricingSchemeVersionAction $action, string $pricingScheme): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $authUser, $pricingScheme);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
