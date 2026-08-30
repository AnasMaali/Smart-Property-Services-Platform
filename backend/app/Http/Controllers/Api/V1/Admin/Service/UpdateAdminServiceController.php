<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminUpdateServiceMetadataAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminServiceRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UpdateAdminServiceController extends Controller
{
    public function __invoke(UpdateAdminServiceRequest $request, AdminUpdateServiceMetadataAction $action, string $service): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle($request, $service, $authUser, [
            'name' => $request->string('name')->toString(),
            'short_description' => $request->input('short_description'),
            'description' => $request->input('description'),
            'display_order' => $request->integer('display_order'),
        ]);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
