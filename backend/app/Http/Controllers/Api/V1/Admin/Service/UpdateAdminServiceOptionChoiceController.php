<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminServiceOptionChoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminServiceOptionChoiceRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UpdateAdminServiceOptionChoiceController extends Controller
{
    public function __invoke(UpdateAdminServiceOptionChoiceRequest $request, AdminServiceOptionChoiceAction $action, string $choice): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $data = $request->validated();
        $data['description'] = $request->input('description');
        $data['display_order'] = $request->integer('display_order');

        $result = $action->update($request, $authUser, $choice, $data);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
