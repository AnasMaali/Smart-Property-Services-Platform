<?php

namespace App\Actions\Admin\Service;

use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B23 - the small read-only lookup the Service "Required
 * specializations" editor needs to populate its dropdown. Every
 * specialization is returned regardless of `is_active` (an Admin choosing
 * what a Service requires needs to see the full picture, same convention
 * as every other Admin catalog list in this module).
 */
final class AdminListSpecializationsAction
{
    use BuildsCartResult;

    public function handle(): array
    {
        $rows = DB::table('specializations')->orderBy('display_order')->get(['id', 'code', 'name', 'is_active']);

        return $this->ok(200, 'Specializations retrieved successfully.', [
            'specializations' => $rows->map(fn ($row) => [
                'id' => (int) $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'is_active' => (bool) $row->is_active,
            ])->values()->all(),
        ]);
    }
}
