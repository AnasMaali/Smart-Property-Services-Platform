<?php

namespace App\Actions\Admin\Service;

use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B23-ext - the small read-only lookup the Checkpoint editor
 * needs to populate its action-type dropdown (REPLACE/INSPECT/
 * INSPECT_AND_CLEAN/TOP_UP/INSPECT_AND_ADJUST/UPDATE). Seed-only
 * vocabulary - Admin selects from these, never supplies an arbitrary code
 * (see App\Actions\Admin\Service\AdminServiceCheckpointAction's docblock).
 */
final class AdminListServiceCheckpointActionTypesAction
{
    use BuildsCartResult;

    public function handle(): array
    {
        $rows = DB::table('service_checkpoint_action_types')->orderBy('display_order')->get(['id', 'code', 'name', 'is_active']);

        return $this->ok(200, 'Checkpoint action types retrieved successfully.', [
            'action_types' => $rows->map(fn ($row) => [
                'code' => $row->code,
                'name' => $row->name,
                'is_active' => (bool) $row->is_active,
            ])->values()->all(),
        ]);
    }
}
