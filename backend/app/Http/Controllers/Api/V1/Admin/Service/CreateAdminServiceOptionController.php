<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminServiceOptionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAdminServiceOptionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CreateAdminServiceOptionController extends Controller
{
    public function __invoke(CreateAdminServiceOptionRequest $request, AdminServiceOptionAction $action, string $service): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $data = $request->validated();
        $data['description'] = $request->input('description');
        $data['is_required'] = $request->boolean('is_required');
        $data['display_order'] = $request->integer('display_order', 0);

        $result = $action->create($request, $authUser, $service, $data);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
