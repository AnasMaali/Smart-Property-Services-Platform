<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RegisterCustomerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterCustomerRequest;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    public function __invoke(RegisterCustomerRequest $request, RegisterCustomerAction $action): JsonResponse
    {
        $result = $action->handle($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Registration successful. Please verify your phone number using the OTP sent to it.',
            'data' => $result,
        ], 201);
    }
}
