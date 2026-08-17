<?php

namespace App\Http\Controllers\Api\V1\Property;

use App\Actions\Property\GetPropertyAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetPropertyController extends Controller
{
    public function __invoke(Request $request, GetPropertyAction $action, string $property): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($authUser->id, $property);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
