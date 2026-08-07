<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ResendPhoneOtpAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResendOtpRequest;
use Illuminate\Http\JsonResponse;

class ResendOtpController extends Controller
{
    public function __invoke(ResendOtpRequest $request, ResendPhoneOtpAction $action): JsonResponse
    {
        $result = $action->handle($request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['success'] ? 200 : 422);
    }
}
