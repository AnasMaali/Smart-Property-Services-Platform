<?php

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Actions\Admin\Contract\AdminSendContractForAcceptanceAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SendContractForAcceptanceController extends Controller
{
    public function __invoke(Request $request, AdminSendContractForAcceptanceAction $action, string $contract): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $contract, $authUser);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
