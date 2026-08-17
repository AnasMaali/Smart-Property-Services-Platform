<?php

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Actions\Admin\Contract\AdminSuspendContractAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContractActionReasonRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SuspendContractController extends Controller
{
    public function __invoke(ContractActionReasonRequest $request, AdminSuspendContractAction $action, string $contract): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $contract, $authUser, $request->string('reason')->toString() ?: null);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
