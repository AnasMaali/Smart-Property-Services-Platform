<?php

namespace App\Http\Controllers\Api\V1\Contract;

use App\Actions\Contract\RequestContractAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contract\RequestContractRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class RequestContractController extends Controller
{
    public function __invoke(RequestContractRequest $request, RequestContractAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($authUser->id, $request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
