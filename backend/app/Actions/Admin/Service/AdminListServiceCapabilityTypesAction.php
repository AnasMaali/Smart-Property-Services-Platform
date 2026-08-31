<?php

namespace App\Actions\Admin\Service;

use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Support\Facades\DB;

/**
 * The small read-only lookup App\Actions\Admin\Service\
 * AdminSetServiceCapabilitiesAction's editor needs to populate its
 * checkbox list, mirroring App\Actions\Admin\Service\
 * AdminListPaymentMethodTypesAction. Seed-only vocabulary (CART_ELIGIBLE/
 * QUOTE_ONLY/EMERGENCY/SUBSCRIPTION/REQUIRES_SITE_VISIT) - Admin selects
 * from these, never supplies an arbitrary code.
 */
final class AdminListServiceCapabilityTypesAction
{
    use BuildsCartResult;

    public function handle(): array
    {
        $rows = DB::table('service_capability_types')->orderBy('code')->get(['code', 'name', 'description', 'is_active']);

        return $this->ok(200, 'Service capability types retrieved successfully.', [
            'service_capability_types' => $rows->map(fn ($row) => [
                'code' => $row->code,
                'name' => $row->name,
                'description' => $row->description,
                'is_active' => (bool) $row->is_active,
            ])->values()->all(),
        ]);
    }
}
