<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminServiceOptionChoiceAttributeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAdminServiceOptionChoiceAttributeRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CreateAdminServiceOptionChoiceAttributeController extends Controller
{
    public function __invoke(CreateAdminServiceOptionChoiceAttributeRequest $request, AdminServiceOptionChoiceAttributeAction $action, string $choice): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->create($request, $authUser, $choice, [
            'attribute_type_code' => $request->string('attribute_type_code')->toString(),
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
