<?php

namespace App\Http\Controllers\Api\V1\Property;

use App\Actions\Property\ArchivePropertyAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeletePropertyController extends Controller
{
    public function __invoke(Request $request, ArchivePropertyAction $action, string $property): JsonResponse
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
