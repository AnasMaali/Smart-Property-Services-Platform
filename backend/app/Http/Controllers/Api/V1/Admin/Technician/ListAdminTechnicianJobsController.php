<?php

namespace App\Http\Controllers\Api\V1\Admin\Technician;

use App\Actions\Admin\Technician\AdminListTechnicianJobsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminTechnicianJobsRequest;
use Illuminate\Http\JsonResponse;

class ListAdminTechnicianJobsController extends Controller
{
    public function __invoke(ListAdminTechnicianJobsRequest $request, AdminListTechnicianJobsAction $action, string $technician): JsonResponse
    {
        $result = $action->handle(
            $technician,
            (int) ($request->integer('page') ?: 1),
            (int) ($request->integer('per_page') ?: AdminListTechnicianJobsAction::DEFAULT_PER_PAGE),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
