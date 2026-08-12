<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Auth\AdminMeAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request, AdminMeAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        return response()->json([
            'success' => true,
            'message' => 'Admin identity retrieved successfully.',
            'data' => $action->handle($authUser->id),
        ], 200);
    }
}
