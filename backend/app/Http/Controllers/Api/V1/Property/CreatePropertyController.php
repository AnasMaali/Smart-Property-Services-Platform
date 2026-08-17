<?php

namespace App\Http\Controllers\Api\V1\Property;

use App\Actions\Property\CreatePropertyAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\CreatePropertyRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CreatePropertyController extends Controller
{
    public function __invoke(CreatePropertyRequest $request, CreatePropertyAction $action): JsonResponse
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
