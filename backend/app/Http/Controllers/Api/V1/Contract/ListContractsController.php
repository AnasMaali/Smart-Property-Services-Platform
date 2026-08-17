<?php

namespace App\Http\Controllers\Api\V1\Contract;

use App\Actions\Contract\ListContractsAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListContractsController extends Controller
{
    public function __invoke(Request $request, ListContractsAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($authUser->id);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
