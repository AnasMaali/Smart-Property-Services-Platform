<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Actions\Auth\AdminLoginAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AdminLoginRequest;
use Illuminate\Http\JsonResponse;

class AdminLoginController extends Controller
{
    public function __invoke(AdminLoginRequest $request, AdminLoginAction $action): JsonResponse
    {
        $result = $action->handle($request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['success'] ? 200 : 422);
    }
}
