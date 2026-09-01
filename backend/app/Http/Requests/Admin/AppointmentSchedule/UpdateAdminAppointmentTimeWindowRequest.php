<?php

namespace App\Http\Requests\Admin\AppointmentSchedule;

use App\Http\Requests\ApiFormRequest;

/**
 * A full replace of the template's editable metadata, not a partial patch -
 * mirrors App\Http\Requests\Admin\UpdateAdminServiceCategoryRequest's exact
 * convention, so a caller cannot accidentally leave a stale value in place
 * by omitting a field. `code` is never editable here, for the same reason
 * a Service Category's `code` is not: it is not read programmatically
 * anywhere yet, but immutability avoids ever having to reconcile a renamed
 * code against historically-generated appointment_slots.
 */
class UpdateAdminAppointmentTimeWindowRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'min:1', 'max:500'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
