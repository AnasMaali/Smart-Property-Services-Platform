<?php

namespace App\Http\Controllers\Api\V1\Admin\Payment;

use App\Actions\Admin\Payment\AdminListPaymentsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminPaymentsRequest;
use Illuminate\Http\JsonResponse;

class ListAdminPaymentsController extends Controller
{
    public function __invoke(ListAdminPaymentsRequest $request, AdminListPaymentsAction $action): JsonResponse
    {
        $filters = array_filter([
            'status' => $request->string('status')->toString() ?: null,
            'checkout_reference' => $request->string('checkout_reference')->toString() ?: null,
            'customer_uuid' => $request->string('customer_uuid')->toString() ?: null,
            'provider_transaction_reference' => $request->string('provider_transaction_reference')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $result = $action->handle(
            $filters,
            (int) ($request->integer('page') ?: 1),
            (int) ($request->integer('per_page') ?: AdminListPaymentsAction::DEFAULT_PER_PAGE),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
