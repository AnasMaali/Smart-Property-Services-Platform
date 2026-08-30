<?php

namespace App\Actions\Admin\Service;

use App\Support\Admin\AdminServiceCategoryPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B8. Unlike the customer-facing App\Actions\ServiceCatalog\
 * ListServiceCategoriesAction (active-only, for the mobile Home screen),
 * Admin sees every Category regardless of `is_active` - an operator needs
 * to see what is currently hidden from the app. Only 18 categories exist
 * (see database/blue_v1_seed.sql), so this is deliberately a small,
 * unpaginated list, matching how few of them there realistically ever are.
 */
final class AdminListServiceCategoriesAction
{
    use BuildsCartResult;

    /**
     * @param  array{is_active?: bool, search?: string}  $filters
     */
    public function handle(array $filters): array
    {
        $query = DB::table('service_categories');

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', $filters['is_active'] ? 1 : 0);
        }

        if (isset($filters['search'])) {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }

        $rows = $query->orderBy('display_order')->get();

        return $this->ok(200, 'Service categories retrieved successfully.', [
            'service_categories' => AdminServiceCategoryPresenter::presentList($rows),
        ]);
    }
}
