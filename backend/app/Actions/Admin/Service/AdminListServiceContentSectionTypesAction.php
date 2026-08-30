<?php

namespace App\Actions\Admin\Service;

use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B23-ext - the small read-only lookup the Content Section
 * editor needs to populate its type dropdown. Seed-only vocabulary (see
 * database/phase23_catalog_model_extension_migration.sql) - Admin selects
 * from these, never supplies an arbitrary code.
 */
final class AdminListServiceContentSectionTypesAction
{
    use BuildsCartResult;

    public function handle(): array
    {
        $rows = DB::table('service_content_section_types')->orderBy('display_order')->get(['id', 'code', 'name', 'is_active']);

        return $this->ok(200, 'Content section types retrieved successfully.', [
            'section_types' => $rows->map(fn ($row) => [
                'code' => $row->code,
                'name' => $row->name,
                'is_active' => (bool) $row->is_active,
            ])->values()->all(),
        ]);
    }
}
