<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Actions\Profile\UpdateProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UpdateProfileController extends Controller
{
    public function __invoke(UpdateProfileRequest $request, UpdateProfileAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => $action->handle($authUser->id, $request->validated()),
        ], 200);
    }
}
