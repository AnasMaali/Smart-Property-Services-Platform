<?php

namespace App\Http\Controllers\Api\V1\Admin\Support;

use App\Actions\Admin\Support\AdminListSupportRequestsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminSupportRequestsRequest;
use Illuminate\Http\JsonResponse;

class ListAdminSupportRequestsController extends Controller
{
    public function __invoke(ListAdminSupportRequestsRequest $request, AdminListSupportRequestsAction $action): JsonResponse
    {
        $filters = array_filter([
            'status' => $request->string('status')->toString() ?: null,
            'customer_uuid' => $request->string('customer_uuid')->toString() ?: null,
            'booking_uuid' => $request->string('booking_uuid')->toString() ?: null,
            'assigned_admin_uuid' => $request->string('assigned_admin_uuid')->toString() ?: null,
            'search' => $request->string('search')->toString() ?: null,
        ], fn ($value) => $value !== null);

        if ($request->boolean('unassigned')) {
            $filters['unassigned'] = true;
        }

        $result = $action->handle(
            $filters,
            (int) ($request->integer('page') ?: 1),
            (int) ($request->integer('per_page') ?: AdminListSupportRequestsAction::DEFAULT_PER_PAGE),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
