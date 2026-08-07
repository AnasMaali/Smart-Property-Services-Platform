<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\VerifyPhoneAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyPhoneRequest;
use Illuminate\Http\JsonResponse;

class VerifyPhoneController extends Controller
{
    public function __invoke(VerifyPhoneRequest $request, VerifyPhoneAction $action): JsonResponse
    {
        $result = $action->handle($request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['success'] ? 200 : 422);
    }
}
