<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminServiceOptionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminServiceOptionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UpdateAdminServiceOptionController extends Controller
{
    public function __invoke(UpdateAdminServiceOptionRequest $request, AdminServiceOptionAction $action, string $option): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $data = $request->validated();
        $data['description'] = $request->input('description');
        $data['is_required'] = $request->boolean('is_required');
        $data['display_order'] = $request->integer('display_order');

        $result = $action->update($request, $authUser, $option, $data);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
