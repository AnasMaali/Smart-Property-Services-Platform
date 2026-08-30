<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminServiceOptionChoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAdminServiceOptionChoiceRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CreateAdminServiceOptionChoiceController extends Controller
{
    public function __invoke(CreateAdminServiceOptionChoiceRequest $request, AdminServiceOptionChoiceAction $action, string $option): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $data = $request->validated();
        $data['description'] = $request->input('description');
        $data['display_order'] = $request->integer('display_order', 0);

        $result = $action->create($request, $authUser, $option, $data);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
