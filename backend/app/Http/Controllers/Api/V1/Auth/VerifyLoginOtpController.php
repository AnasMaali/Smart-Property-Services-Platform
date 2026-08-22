<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\VerifyLoginOtpAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyLoginOtpRequest;
use Illuminate\Http\JsonResponse;

class VerifyLoginOtpController extends Controller
{
    public function __invoke(VerifyLoginOtpRequest $request, VerifyLoginOtpAction $action): JsonResponse
    {
        $result = $action->handle([
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
