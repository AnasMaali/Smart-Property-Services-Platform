<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\LogoutAllAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutAllController extends Controller
{
    public function __invoke(Request $request, LogoutAllAction $action): JsonResponse
    {
        $result = $action->handle($request, $request->bearerToken());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
        ], $result['success'] ? 200 : 401);
    }
}
