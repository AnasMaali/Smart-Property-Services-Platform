<?php

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Actions\Admin\Contract\AdminApproveContractAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveContractRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ApproveContractController extends Controller
{
    public function __invoke(ApproveContractRequest $request, AdminApproveContractAction $action, string $contract): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $contract, $authUser, $request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
