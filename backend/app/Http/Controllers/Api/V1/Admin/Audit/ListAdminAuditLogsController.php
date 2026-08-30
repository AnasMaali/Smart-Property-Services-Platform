<?php

namespace App\Http\Controllers\Api\V1\Admin\Audit;

use App\Actions\Admin\Audit\AdminListAuditLogsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminAuditLogsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class ListAdminAuditLogsController extends Controller
{
    public function __invoke(ListAdminAuditLogsRequest $request, AdminListAuditLogsAction $action): JsonResponse
    {
        $filters = array_filter([
            'action_code' => $request->string('action_code')->toString() ?: null,
            'entity_type' => $request->string('entity_type')->toString() ?: null,
            'entity_identifier' => $request->string('entity_identifier')->toString() ?: null,
            'actor_uuid' => $request->string('actor_uuid')->toString() ?: null,
            'from' => $request->filled('from') ? Carbon::parse($request->input('from')) : null,
            'to' => $request->filled('to') ? Carbon::parse($request->input('to')) : null,
        ], fn ($value) => $value !== null);

        if ($request->has('was_successful')) {
            $filters['was_successful'] = $request->boolean('was_successful');
        }

        $result = $action->handle(
            $filters,
            (int) ($request->integer('page') ?: 1),
            (int) ($request->integer('per_page') ?: AdminListAuditLogsAction::DEFAULT_PER_PAGE),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
