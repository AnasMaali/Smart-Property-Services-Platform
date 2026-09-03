<?php

namespace App\Http\Controllers\Api\V1\Support;

use App\Actions\Support\CreateCustomerSupportRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\CreateCustomerSupportRequestRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CreateCustomerSupportRequestController extends Controller
{
    public function __invoke(CreateCustomerSupportRequestRequest $request, CreateCustomerSupportRequestAction $action): JsonResponse
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
