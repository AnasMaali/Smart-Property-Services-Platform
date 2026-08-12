<?php

namespace App\Http\Controllers\Api\V1\Admin\Technician;

use App\Actions\Admin\Technician\AdminListTechniciansAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminTechniciansRequest;
use Illuminate\Http\JsonResponse;

class ListAdminTechniciansController extends Controller
{
    public function __invoke(ListAdminTechniciansRequest $request, AdminListTechniciansAction $action): JsonResponse
    {
        $filters = array_filter([
            'status' => $request->string('status')->toString() ?: null,
            'specialization' => $request->string('specialization')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $result = $action->handle(
            $filters,
            (int) ($request->integer('page') ?: 1),
            (int) ($request->integer('per_page') ?: AdminListTechniciansAction::DEFAULT_PER_PAGE),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
