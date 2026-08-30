<?php

namespace App\Http\Controllers\Api\V1\Admin\Service;

use App\Actions\Admin\Service\AdminSetServiceOriginalPriceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetAdminServiceOriginalPriceRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SetAdminServiceOriginalPriceController extends Controller
{
    public function __invoke(SetAdminServiceOriginalPriceRequest $request, AdminSetServiceOriginalPriceAction $action, string $service): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $originalPrice = $request->input('original_price');

        $result = $action->handle($request, $authUser, $service, $originalPrice === null ? null : (string) $originalPrice);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
