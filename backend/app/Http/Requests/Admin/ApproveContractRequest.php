<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Shape-only validation for POST /v1/admin/contracts/{contract}/approve.
 * Whether each service is actually CONTRACT-eligible is a catalog rule left
 * to App\Actions\Admin\Contract\AdminApproveContractAction - the same
 * "shape here, catalog rules in the Action" split used throughout this
 * codebase.
 *
 * The Admin always explicitly lists every covered service and its
 * entitlement terms here, even when the customer originally requested "all
 * services" - BLUE V1 Phase 10C is explicit that "all services" must
 * resolve to explicit, historical service_contract_items rows at approval
 * time and must never be re-resolved automatically later, so there is no
 * server-side "just copy every eligible service" shortcut for the Admin to
 * opt into.
 *
 * BLUE V1 Phase 11 adds the required recurring Stripe Billing terms
 * (billing_interval / recurring_amount / currency_code) the Admin defines
 * at this exact moment - these become the immutable commercial snapshot on
 * `service_contract_billings` (see AdminApproveContractAction). The
 * customer never supplies any of these three fields anywhere in this API -
 * only an Admin, only here.
 */
class ApproveContractRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'internal_note' => is_string($this->internal_note) ? trim($this->internal_note) : $this->internal_note,
        ]);
    }

    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'term_months' => ['nullable', 'integer', 'min:1', 'max:120'],

            'services' => ['required', 'array', 'min:1'],
            'services.*.service_uuid' => ['required', 'uuid', 'distinct'],
            'services.*.entitlement_mode' => ['required', 'string', 'in:LIMITED_VISITS,UNLIMITED'],
            'services.*.included_visits' => ['nullable', 'integer', 'min:1', 'max:1000'],

            'quoted_amount' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['nullable', 'string', 'size:3'],

            // BLUE V1 Phase 11 - required recurring Stripe Billing terms.
            'billing_interval' => ['required', 'string', 'in:MONTHLY,YEARLY'],
            'recurring_amount' => ['required', 'numeric', 'min:0.01'],
            'billing_currency_code' => ['required', 'string', 'size:3'],

            'internal_note' => ['nullable', 'string', 'min:2', 'max:1000'],
        ];
    }
}
