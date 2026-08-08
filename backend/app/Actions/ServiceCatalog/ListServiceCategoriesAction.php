<?php

namespace App\Actions\ServiceCatalog;

use Illuminate\Support\Facades\DB;

class ListServiceCategoriesAction
{
    /**
     * Active-only service categories for the customer Home screen, ordered
     * by display_order.
     *
     * @return array<int, array{id: int, code: string, name: string, description: ?string}>
     */
    public function handle(): array
    {
        return DB::table('service_categories')
            ->where('is_active', 1)
            ->orderBy('display_order')
            ->get(['id', 'code', 'name', 'description'])
            ->map(fn ($row) => (array) $row)
            ->values()
            ->all();
    }
}
