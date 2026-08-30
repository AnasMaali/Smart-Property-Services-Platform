<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminServiceOptionChoiceAttributeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminServiceOptionChoiceAttributeRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UpdateAdminServiceOptionChoiceAttributeController extends Controller
{
    public function __invoke(UpdateAdminServiceOptionChoiceAttributeRequest $request, AdminServiceOptionChoiceAttributeAction $action, string $attribute): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->update($request, $authUser, $attribute, [
            'value' => $request->string('value')->toString(),
        ]);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
