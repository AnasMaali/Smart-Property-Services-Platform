<?php

namespace App\Http\Controllers\Api\V1\Admin\Financial;

use App\Actions\Admin\Financial\AdminListFinancialLedgerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminFinancialLedgerRequest;
use Illuminate\Http\JsonResponse;

class ListAdminFinancialLedgerController extends Controller
{
    public function __invoke(ListAdminFinancialLedgerRequest $request, AdminListFinancialLedgerAction $action): JsonResponse
    {
        $filters = array_filter([
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'event_type' => $request->string('event_type')->toString() ?: null,
            'payment_method' => $request->string('payment_method')->toString() ?: null,
            'direction' => $request->string('direction')->toString() ?: null,
            'booking_uuid' => $request->string('booking_uuid')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $result = $action->handle(
            $filters,
            (int) ($request->integer('page') ?: 1),
            (int) ($request->integer('per_page') ?: AdminListFinancialLedgerAction::DEFAULT_PER_PAGE),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
