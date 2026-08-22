<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\IssueLoginOtpAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResendLoginOtpRequest;
use Illuminate\Http\JsonResponse;

class ResendLoginOtpController extends Controller
{
    public function __invoke(ResendLoginOtpRequest $request, IssueLoginOtpAction $action): JsonResponse
    {
        $result = $action->handle($request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], 200);
    }
}
