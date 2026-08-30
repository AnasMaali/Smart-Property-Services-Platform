<?php

namespace App\Http\Controllers\Api\V1\Admin\Rating;

use App\Actions\Admin\Rating\AdminGetRatingAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class GetAdminRatingController extends Controller
{
    public function __invoke(AdminGetRatingAction $action, string $booking): JsonResponse
    {
        $result = $action->handle($booking);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
