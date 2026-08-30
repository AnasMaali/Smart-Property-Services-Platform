<?php

namespace App\Actions\Admin\Service;

use App\Support\Admin\AdminServicePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Support\Facades\DB;

/**
 * Global (cross-category) Service listing for Admin operators (BLUE V1
 * Phase B8) - unlike the customer-facing App\Actions\ServiceCatalog\
 * ListCategoryServicesAction (one active Category's active Services only),
 * this sees every Service regardless of its own or its Category's
 * `is_active` state. Deterministic ordering (`updated_at DESC, id DESC`)
 * and a bounded page size make this safe against an unbounded table.
 */
final class AdminListServicesAction
{
    use BuildsCartResult;

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{category_id?: int, is_active?: bool, search?: string}  $filters
     */
    public function handle(array $filters, int $page, int $perPage): array
    {
        $page = max($page, 1);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        $query = DB::table('services')
            ->join('service_categories', 'service_categories.id', '=', 'services.category_id');

        if (isset($filters['category_id'])) {
            $query->where('services.category_id', $filters['category_id']);
        }

        if (isset($filters['is_active'])) {
            $query->where('services.is_active', $filters['is_active'] ? 1 : 0);
        }

        if (isset($filters['search'])) {
            $query->where('services.name', 'like', '%'.$filters['search'].'%');
        }

        $total = (clone $query)->count('services.id');
        $lastPage = max((int) ceil($total / $perPage), 1);

        $rows = $query
            ->orderByDesc('services.updated_at')
            ->orderByDesc('services.id')
            ->forPage($page, $perPage)
            ->get([
                'services.id',
                'services.code',
                'services.name',
                'services.is_active',
                'services.display_order',
                'services.updated_at',
                'service_categories.id as category_id',
                'service_categories.name as category_name',
            ]);

        return $this->ok(200, 'Services retrieved successfully.', [
            'services' => AdminServicePresenter::presentList($rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }
}
