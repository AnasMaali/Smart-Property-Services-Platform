<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports\Payment;

use App\Actions\Admin\Reports\AdminPaymentReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GetAdminPaymentReportRequest;
use Illuminate\Http\JsonResponse;

class GetAdminPaymentReportController extends Controller
{
    public function __invoke(GetAdminPaymentReportRequest $request, AdminPaymentReportAction $action): JsonResponse
    {
        $filters = array_filter([
            'range' => $request->string('range')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'payment_method' => $request->string('payment_method')->toString() ?: null,
            'booking_uuid' => $request->string('booking_uuid')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $result = $action->screen($filters, (int) $request->integer('page', 1), (int) $request->integer('per_page', AdminPaymentReportAction::DEFAULT_PER_PAGE));

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'] ?? null,
        ], $result['status']);
    }
}
