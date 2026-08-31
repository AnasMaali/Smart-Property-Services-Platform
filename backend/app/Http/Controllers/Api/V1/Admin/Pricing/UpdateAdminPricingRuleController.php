<?php

namespace App\Http\Controllers\Api\V1\Admin\Pricing;

use App\Actions\Admin\Pricing\AdminUpdatePricingRuleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminPricingRuleRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UpdateAdminPricingRuleController extends Controller
{
    public function __invoke(UpdateAdminPricingRuleRequest $request, AdminUpdatePricingRuleAction $action, string $pricingScheme, string $rule): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $authUser, $pricingScheme, $rule, $request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
