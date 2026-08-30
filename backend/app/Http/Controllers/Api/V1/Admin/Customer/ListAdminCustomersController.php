<?php

namespace App\Http\Controllers\Api\V1\Admin\Customer;

use App\Actions\Admin\Customer\AdminListCustomersAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminCustomersRequest;
use Illuminate\Http\JsonResponse;

class ListAdminCustomersController extends Controller
{
    public function __invoke(ListAdminCustomersRequest $request, AdminListCustomersAction $action): JsonResponse
    {
        $filters = array_filter([
            'account_status' => $request->string('account_status')->toString() ?: null,
            'phone_number' => $request->string('phone_number')->toString() ?: null,
            'email' => $request->string('email')->toString() ?: null,
            'customer_uuid' => $request->string('customer_uuid')->toString() ?: null,
            'search' => $request->string('search')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $result = $action->handle(
            $filters,
            (int) ($request->integer('page') ?: 1),
            (int) ($request->integer('per_page') ?: AdminListCustomersAction::DEFAULT_PER_PAGE),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
