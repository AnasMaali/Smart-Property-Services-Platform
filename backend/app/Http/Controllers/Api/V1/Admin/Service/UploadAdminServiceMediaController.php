<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminServiceMediaAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UploadAdminServiceMediaRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UploadAdminServiceMediaController extends Controller
{
    public function __invoke(UploadAdminServiceMediaRequest $request, AdminServiceMediaAction $action, string $service): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->upload(
            $request,
            $authUser,
            $service,
            $request->file('file'),
            $request->string('alt_text')->toString(),
            $request->input('caption'),
            $request->boolean('is_primary'),
            $request->integer('display_order', 0),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
