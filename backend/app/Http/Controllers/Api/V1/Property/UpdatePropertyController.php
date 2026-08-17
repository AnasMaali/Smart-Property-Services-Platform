<?php

namespace App\Http\Controllers\Api\V1\Property;

use App\Actions\Property\UpdatePropertyAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\UpdatePropertyRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UpdatePropertyController extends Controller
{
    public function __invoke(UpdatePropertyRequest $request, UpdatePropertyAction $action, string $property): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($authUser->id, $property, $request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
