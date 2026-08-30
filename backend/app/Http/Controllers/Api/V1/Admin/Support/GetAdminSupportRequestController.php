<?php

namespace App\Http\Controllers\Api\V1\Admin\Support;

use App\Actions\Admin\Support\AdminGetSupportRequestAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class GetAdminSupportRequestController extends Controller
{
    public function __invoke(AdminGetSupportRequestAction $action, string $supportRequest): JsonResponse
    {
        $result = $action->handle($supportRequest);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
