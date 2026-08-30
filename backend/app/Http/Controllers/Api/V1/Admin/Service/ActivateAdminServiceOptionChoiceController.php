<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminServiceOptionChoiceAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivateAdminServiceOptionChoiceController extends Controller
{
    public function __invoke(Request $request, AdminServiceOptionChoiceAction $action, string $choice): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->setActive($request, $authUser, $choice, true);

        return response()->json(['success' => $result['success'], 'message' => $result['message'], 'data' => $result['data']], $result['status']);
    }
}
