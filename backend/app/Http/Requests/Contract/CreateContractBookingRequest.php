<?php

namespace App\Http\Requests\Contract;

use App\Http\Requests\ApiFormRequest;

/**
 * Only what the customer actually chooses: which appointment slot. Never
 * accepts service, contract, entitlement, price, or booking_source - all of
 * those are server/domain-owned (BLUE V1 Phase 10F).
 */
class CreateContractBookingRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'appointment_slot_uuid' => ['required', 'uuid'],
        ];
    }
}
