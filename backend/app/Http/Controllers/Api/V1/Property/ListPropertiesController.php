<?php

namespace App\Http\Controllers\Api\V1\Property;

use App\Actions\Property\ListPropertiesAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListPropertiesController extends Controller
{
    public function __invoke(Request $request, ListPropertiesAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $filter = $request->string('status')->toString();
        $filter = in_array($filter, ['active', 'archived', 'all'], true) ? $filter : 'active';

        $result = $action->handle($authUser->id, $filter);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
