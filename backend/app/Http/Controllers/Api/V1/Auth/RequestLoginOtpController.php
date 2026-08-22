<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\IssueLoginOtpAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestLoginOtpRequest;
use Illuminate\Http\JsonResponse;

class RequestLoginOtpController extends Controller
{
    public function __invoke(RequestLoginOtpRequest $request, IssueLoginOtpAction $action): JsonResponse
    {
        $result = $action->handle($request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], 200);
    }
}
