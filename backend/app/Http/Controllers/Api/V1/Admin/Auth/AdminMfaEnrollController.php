<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Actions\Auth\AdminMfaEnrollAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AdminMfaEnrollRequest;
use Illuminate\Http\JsonResponse;

class AdminMfaEnrollController extends Controller
{
    public function __invoke(AdminMfaEnrollRequest $request, AdminMfaEnrollAction $action): JsonResponse
    {
        $result = $action->handle($request, $request->validated());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['success'] ? 200 : 422);
    }
}
