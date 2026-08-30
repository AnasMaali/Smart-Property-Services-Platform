<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminCreateServiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAdminServiceRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CreateAdminServiceController extends Controller
{
    public function __invoke(CreateAdminServiceRequest $request, AdminCreateServiceAction $action): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $authUser, [
            'category_id' => $request->integer('category_id'),
            'code' => $request->string('code')->toString(),
            'slug' => $request->string('slug')->toString(),
            'name' => $request->string('name')->toString(),
            'short_description' => $request->input('short_description'),
            'description' => $request->input('description'),
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
