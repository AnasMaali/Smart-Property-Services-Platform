<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminServiceOptionChoiceAttributeAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivateAdminServiceOptionChoiceAttributeController extends Controller
{
    public function __invoke(Request $request, AdminServiceOptionChoiceAttributeAction $action, string $attribute): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->setActive($request, $authUser, $attribute, true);

        return response()->json(['success' => $result['success'], 'message' => $result['message'], 'data' => $result['data']], $result['status']);
    }
}
