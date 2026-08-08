<?php

namespace App\Http\Controllers\Api\V1\ReferenceData;

use App\Actions\ReferenceData\GetRegistrationReferenceDataAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ReferenceDataController extends Controller
{
    public function __invoke(GetRegistrationReferenceDataAction $action): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Reference data retrieved successfully.',
            'data' => $action->handle(),
        ], 200);
    }
}
