<?php

namespace App\Http\Controllers\Api\V1\Admin\Pricing;

use App\Actions\Admin\Pricing\AdminCreatePricingRuleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAdminPricingRuleRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CreateAdminPricingRuleController extends Controller
{
    public function __invoke(CreateAdminPricingRuleRequest $request, AdminCreatePricingRuleAction $action, string $pricingScheme): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $authUser, $pricingScheme, $request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
