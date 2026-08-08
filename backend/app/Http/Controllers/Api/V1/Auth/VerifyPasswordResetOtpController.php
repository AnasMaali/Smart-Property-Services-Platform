<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\VerifyPasswordResetOtpAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyPasswordResetOtpRequest;
use Illuminate\Http\JsonResponse;

class VerifyPasswordResetOtpController extends Controller
{
    public function __invoke(VerifyPasswordResetOtpRequest $request, VerifyPasswordResetOtpAction $action): JsonResponse
    {
        $result = $action->handle($request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['success'] ? 200 : 422);
    }
}
