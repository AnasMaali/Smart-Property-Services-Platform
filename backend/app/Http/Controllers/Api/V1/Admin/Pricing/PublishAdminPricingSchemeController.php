<?php

namespace App\Http\Controllers\Api\V1\Admin\Pricing;

use App\Actions\Admin\Pricing\AdminPublishPricingSchemeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PublishAdminPricingSchemeRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class PublishAdminPricingSchemeController extends Controller
{
    public function __invoke(PublishAdminPricingSchemeRequest $request, AdminPublishPricingSchemeAction $action, string $pricingScheme): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $effectiveTo = $request->input('effective_to');

        $result = $action->handle(
            $request,
            $authUser,
            $pricingScheme,
            Carbon::parse($request->input('effective_from')),
            $effectiveTo === null ? null : Carbon::parse($effectiveTo),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
