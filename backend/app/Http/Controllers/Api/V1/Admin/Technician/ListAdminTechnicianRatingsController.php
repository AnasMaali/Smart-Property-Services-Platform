<?php

namespace App\Http\Controllers\Api\V1\Admin\Technician;

use App\Actions\Admin\Technician\AdminListTechnicianRatingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminTechnicianRatingsRequest;
use Illuminate\Http\JsonResponse;

class ListAdminTechnicianRatingsController extends Controller
{
    public function __invoke(ListAdminTechnicianRatingsRequest $request, AdminListTechnicianRatingsAction $action, string $technician): JsonResponse
    {
        $result = $action->handle(
            $technician,
            (int) ($request->integer('page') ?: 1),
            (int) ($request->integer('per_page') ?: AdminListTechnicianRatingsAction::DEFAULT_PER_PAGE),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
