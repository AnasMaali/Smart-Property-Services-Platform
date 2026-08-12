<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Actions\Auth\AdminRefreshTokenAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RefreshRequest;
use Illuminate\Http\JsonResponse;

class AdminRefreshController extends Controller
{
    public function __invoke(RefreshRequest $request, AdminRefreshTokenAction $action): JsonResponse
    {
        $result = $action->handle($request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['success'] ? 200 : 422);
    }
}
