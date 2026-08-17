<?php

namespace App\Http\Controllers\Api\V1\Contract;

use App\Actions\Contract\CreateContractBookingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contract\CreateContractBookingRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CreateContractBookingController extends Controller
{
    public function __invoke(CreateContractBookingRequest $request, CreateContractBookingAction $action, string $contract, string $contractItem): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->attributes->get('auth_user');

        $result = $action->handle(
            $authUser->id,
            $contract,
            $contractItem,
            $request->string('appointment_slot_uuid')->toString(),
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
        ], $result['status']);
    }
}
