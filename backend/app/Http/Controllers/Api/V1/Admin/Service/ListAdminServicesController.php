<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminListServicesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminServicesRequest;
use Illuminate\Http\JsonResponse;

class ListAdminServicesController extends Controller
{
    public function __invoke(ListAdminServicesRequest $request, AdminListServicesAction $action): JsonResponse
    {
        $filters = [];

        if ($request->filled('category_id')) {
            $filters['category_id'] = $request->integer('category_id');
        }

        if ($request->has('is_active')) {
            $filters['is_active'] = $request->boolean('is_active');
        }

        if ($request->filled('search')) {
            $filters['search'] = $request->string('search')->toString();
        }

        $result = $action->handle(
            $filters,
            (int) ($request->integer('page') ?: 1),
            (int) ($request->integer('per_page') ?: AdminListServicesAction::DEFAULT_PER_PAGE),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
