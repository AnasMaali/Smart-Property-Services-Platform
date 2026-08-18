<?php

namespace App\Http\Controllers\Api\V1\Contract\Billing;

use App\Actions\Contract\Billing\CreateContractBillingCheckoutAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /v1/contracts/{contract}/billing/checkout (BLUE V1 Phase 11). No
 * request body is ever read - the customer supplies nothing but the
 * Contract id in the URL; every monetary/provider value comes from the
 * server-authoritative `service_contract_billings` snapshot (see
 * CreateContractBillingCheckoutAction).
 */
class CreateContractBillingCheckoutController extends Controller
{
    public function __invoke(Request $request, CreateContractBillingCheckoutAction $action, string $contract): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($authUser->id, $contract);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
