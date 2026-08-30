<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Actions\Auth\AdminMfaVerifyAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AdminMfaVerifyRequest;
use Illuminate\Http\JsonResponse;

class AdminMfaVerifyController extends Controller
{
    public function __invoke(AdminMfaVerifyRequest $request, AdminMfaVerifyAction $action): JsonResponse
    {
        $result = $action->handle($request, [
            ...$request->validated(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['success'] ? 200 : 422);
    }
}
