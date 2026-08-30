<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminServiceContentSectionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAdminServiceContentSectionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CreateAdminServiceContentSectionController extends Controller
{
    public function __invoke(CreateAdminServiceContentSectionRequest $request, AdminServiceContentSectionAction $action, string $service): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->create($request, $authUser, $service, [
            'section_type_code' => $request->string('section_type_code')->toString(),
            'title' => $request->string('title')->toString(),
            'body' => $request->string('body')->toString(),
            'display_order' => $request->integer('display_order', 0),
        ]);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
