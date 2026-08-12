<?php

namespace App\Http\Controllers\Api\V1\Admin\Technician;

use App\Actions\Admin\Technician\AdminListTechnicianCandidatesAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListTechnicianCandidatesController extends Controller
{
    public function __invoke(Request $request, AdminListTechnicianCandidatesAction $action, string $bookingItem): JsonResponse
    {
        $result = $action->handle($bookingItem);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
