<?php

namespace App\Http\Controllers\Api\V1\Admin\Customer;

use App\Actions\Admin\Customer\AdminGetCustomerAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class GetAdminCustomerController extends Controller
{
    public function __invoke(AdminGetCustomerAction $action, string $customer): JsonResponse
    {
        $result = $action->handle($customer);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
