<?php

namespace App\Actions\Admin\Pricing;

use App\Support\Admin\AdminPricingSchemePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Global (cross-service) Pricing Scheme Version listing (BLUE V1 Phase
 * B9) - reads the exact same `pricing_scheme_versions` rows the real
 * pricing engine reads, never a parallel view of pricing state.
 * Deterministic ordering (`updated_at DESC, id DESC`) and a
 * bounded page size make this safe against an unbounded table.
 */
final class AdminListPricingSchemesAction
{
    use BuildsCartResult;

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{service_uuid?: string, status?: string, currency?: string}  $filters
     */
    public function handle(array $filters, int $page, int $perPage): array
    {
        $page = max($page, 1);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        if (isset($filters['service_uuid'])) {
            try {
                $filters['service_uuid'] = UuidBinary::toBinary($filters['service_uuid']);
            } catch (InvalidArgumentException) {
                return $this->ok(200, 'Pricing scheme versions retrieved successfully.', [
                    'pricing_schemes' => [],
                    'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1],
                ]);
            }
        }

        $query = DB::table('pricing_scheme_versions')
            ->join('services', 'services.id', '=', 'pricing_scheme_versions.service_id')
            ->join('currencies', 'currencies.id', '=', 'pricing_scheme_versions.currency_id');

        if (isset($filters['service_uuid'])) {
            $query->where('pricing_scheme_versions.service_id', $filters['service_uuid']);
        }

        if (isset($filters['status'])) {
            $query->where('pricing_scheme_versions.status', $filters['status']);
        }

        if (isset($filters['currency'])) {
            $query->where('currencies.code', $filters['currency']);
        }

        $total = (clone $query)->count('pricing_scheme_versions.id');
        $lastPage = max((int) ceil($total / $perPage), 1);

        $rows = $query
            ->orderByDesc('pricing_scheme_versions.updated_at')
            ->orderByDesc('pricing_scheme_versions.id')
            ->forPage($page, $perPage)
            ->get([
                'pricing_scheme_versions.id',
                'pricing_scheme_versions.service_id',
                'pricing_scheme_versions.status',
                'pricing_scheme_versions.effective_from',
                'pricing_scheme_versions.effective_to',
                'pricing_scheme_versions.created_at',
                'pricing_scheme_versions.updated_at',
                'services.name as service_name',
                'currencies.code as currency_code',
                'currencies.symbol as currency_symbol',
            ]);

        return $this->ok(200, 'Pricing scheme versions retrieved successfully.', [
            'pricing_schemes' => AdminPricingSchemePresenter::presentList($rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }
}
