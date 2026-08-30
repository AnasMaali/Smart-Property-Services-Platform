<?php

namespace App\Actions\Admin\Service;

use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B24 - the small read-only lookup the Service Payment
 * Policy editor needs to populate its checkbox list. Seed-only vocabulary
 * (CARD/APPLE_PAY/PAY_ON_SITE) - Admin selects from these, never supplies
 * an arbitrary code.
 */
final class AdminListPaymentMethodTypesAction
{
    use BuildsCartResult;

    public function handle(): array
    {
        $rows = DB::table('payment_method_types')->orderBy('display_order')->get(['code', 'name', 'is_active']);

        return $this->ok(200, 'Payment method types retrieved successfully.', [
            'payment_method_types' => $rows->map(fn ($row) => [
                'code' => $row->code,
                'name' => $row->name,
                'is_active' => (bool) $row->is_active,
            ])->values()->all(),
        ]);
    }
}
