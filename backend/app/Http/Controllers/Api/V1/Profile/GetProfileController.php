<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Actions\Profile\GetProfileAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetProfileController extends Controller
{
    public function __invoke(Request $request, GetProfileAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        return response()->json([
            'success' => true,
            'message' => 'Profile retrieved successfully.',
            'data' => $action->handle($authUser->id),
        ], 200);
    }
}
