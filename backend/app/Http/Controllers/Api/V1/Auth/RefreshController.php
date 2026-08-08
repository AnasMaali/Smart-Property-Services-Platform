<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RefreshTokenAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RefreshRequest;
use Illuminate\Http\JsonResponse;

class RefreshController extends Controller
{
    public function __invoke(RefreshRequest $request, RefreshTokenAction $action): JsonResponse
    {
        $result = $action->handle($request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['success'] ? 200 : 422);
    }
}
