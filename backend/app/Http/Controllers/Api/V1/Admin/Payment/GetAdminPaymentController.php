<?php

namespace App\Http\Controllers\Api\V1\Admin\Payment;

use App\Actions\Admin\Payment\AdminGetPaymentAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class GetAdminPaymentController extends Controller
{
    public function __invoke(AdminGetPaymentAction $action, string $payment): JsonResponse
    {
        $result = $action->handle($payment);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
