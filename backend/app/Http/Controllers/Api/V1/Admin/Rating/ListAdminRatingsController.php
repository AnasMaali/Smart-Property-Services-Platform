<?php

namespace App\Http\Controllers\Api\V1\Admin\Rating;

use App\Actions\Admin\Rating\AdminListRatingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminRatingsRequest;
use Illuminate\Http\JsonResponse;

class ListAdminRatingsController extends Controller
{
    public function __invoke(ListAdminRatingsRequest $request, AdminListRatingsAction $action): JsonResponse
    {
        $filters = array_filter([
            'rating_value' => $request->integer('rating_value') ?: null,
            'max_rating' => $request->integer('max_rating') ?: null,
            'booking_uuid' => $request->string('booking_uuid')->toString() ?: null,
            'customer_uuid' => $request->string('customer_uuid')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $result = $action->handle(
            $filters,
            (int) ($request->integer('page') ?: 1),
            (int) ($request->integer('per_page') ?: AdminListRatingsAction::DEFAULT_PER_PAGE),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
