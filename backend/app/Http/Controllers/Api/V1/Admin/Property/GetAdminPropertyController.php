<?php

namespace App\Http\Controllers\Api\V1\Admin\Property;

use App\Actions\Admin\Property\AdminGetPropertyAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class GetAdminPropertyController extends Controller
{
    public function __invoke(AdminGetPropertyAction $action, string $property): JsonResponse
    {
        $result = $action->handle($property);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
