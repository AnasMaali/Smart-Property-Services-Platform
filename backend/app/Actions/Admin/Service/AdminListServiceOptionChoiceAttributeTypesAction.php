<?php

namespace App\Actions\Admin\Service;

use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B23-ext - the small read-only lookup the Choice Attribute
 * editor needs to populate its type dropdown (DURATION_MINUTES/OIL_BRAND/
 * OIL_GRADE/RECOMMENDED_ODOMETER_KM, ...). Seed-only vocabulary, extensible
 * only by a future migration - Admin selects from these and supplies a
 * plain value; the `data_type` tells the client whether to render a text
 * or numeric input.
 */
final class AdminListServiceOptionChoiceAttributeTypesAction
{
    use BuildsCartResult;

    public function handle(): array
    {
        $rows = DB::table('service_option_choice_attribute_types')->orderBy('display_order')->get(['id', 'code', 'name', 'data_type', 'is_active']);

        return $this->ok(200, 'Choice attribute types retrieved successfully.', [
            'attribute_types' => $rows->map(fn ($row) => [
                'code' => $row->code,
                'name' => $row->name,
                'data_type' => $row->data_type,
                'is_active' => (bool) $row->is_active,
            ])->values()->all(),
        ]);
    }
}
