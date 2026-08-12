<?php

namespace App\Actions\Admin\Technician;

use App\Support\Admin\AdminTechnicianPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Support\Facades\DB;

/**
 * Read-only, paginated Technician listing for Admin operators (BLUE V1
 * Phase 9B) - the safe way to browse Technician records before choosing one
 * to assign (docs/03-features-and-requirements/07-technician-assignment.md
 * "Technician Information"). Deterministic ordering (`full_name ASC, id ASC`)
 * and a bounded page size, matching App\Actions\Admin\Booking\AdminListBookingsAction.
 */
final class AdminListTechniciansAction
{
    use BuildsCartResult;

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{status?: string, specialization?: string}  $filters
     * @return array<string, mixed>
     */
    public function handle(array $filters, int $page, int $perPage): array
    {
        $page = max($page, 1);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        $query = DB::table('technicians')
            ->join('technician_statuses', 'technician_statuses.id', '=', 'technicians.status_id');

        if (isset($filters['status'])) {
            $query->where('technician_statuses.code', $filters['status']);
        }

        if (isset($filters['specialization'])) {
            $query->whereIn('technicians.id', function ($subQuery) use ($filters) {
                $subQuery->select('technician_specializations.technician_id')
                    ->from('technician_specializations')
                    ->join('specializations', 'specializations.id', '=', 'technician_specializations.specialization_id')
                    ->where('specializations.code', $filters['specialization'])
                    ->where('technician_specializations.is_active', 1);
            });
        }

        $total = (clone $query)->count('technicians.id');
        $lastPage = max((int) ceil($total / $perPage), 1);

        $rows = $query
            ->orderBy('technicians.full_name')
            ->orderBy('technicians.id')
            ->forPage($page, $perPage)
            ->get(['technicians.*', 'technician_statuses.code as status_code']);

        return $this->ok(200, 'Technicians retrieved successfully.', [
            'technicians' => AdminTechnicianPresenter::presentList($rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }
}
