<?php

namespace App\Actions\Admin\Technician;

use App\Support\Admin\AdminTechnicianPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only, paginated Technician listing for Admin operators (BLUE V1
 * Phase 9B, extended by BLUE V1 Technician Admin Management with search,
 * assignability/rating filters, and sorting) - the safe way to browse
 * Technician records before choosing one to assign (docs/03-features-and-
 * requirements/07-technician-assignment.md "Technician Information").
 * Deterministic ordering and a bounded page size, matching
 * App\Actions\Admin\Booking\AdminListBookingsAction.
 *
 * Sorting by rating/completed_jobs/active_jobs joins the same derived
 * aggregate tables `ratingAggregateSubquery()`/`jobAggregateSubquery()`
 * build once per request - never one query per Technician (section 24).
 * The rating aggregate applies the exact same "Technician is the only one
 * ever assigned to this Booking" exclusivity rule as
 * AdminTechnicianPresenter::loadRatingSummaries() (see that class's
 * docblock) so a sorted/filtered list never disagrees with the number
 * shown on the Technician's own card.
 */
final class AdminListTechniciansAction
{
    use BuildsCartResult;

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    private const SORTS = ['newest', 'name', 'rating', 'completed_jobs', 'active_jobs'];

    /**
     * @param  array{q?: string, status?: string, specialization?: string, assignable?: bool, rating_min?: float, rating_max?: float, sort?: string}  $filters
     * @return array<string, mixed>
     */
    public function handle(array $filters, int $page, int $perPage): array
    {
        $page = max($page, 1);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);
        $sort = in_array($filters['sort'] ?? null, self::SORTS, true) ? $filters['sort'] : 'name';

        $query = DB::table('technicians')
            ->join('technician_statuses', 'technician_statuses.id', '=', 'technicians.status_id');

        if (isset($filters['q']) && $filters['q'] !== '') {
            $needle = '%'.$filters['q'].'%';
            $query->where(function ($q) use ($needle) {
                $q->where('technicians.full_name', 'like', $needle)
                    ->orWhere('technicians.phone_number', 'like', $needle)
                    ->orWhere('technicians.email', 'like', $needle);
            });
        }

        if (isset($filters['status'])) {
            $query->where('technician_statuses.code', $filters['status']);
        }

        if (isset($filters['assignable'])) {
            $query->where('technician_statuses.is_assignable', $filters['assignable'] ? 1 : 0);
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

        $needsRatingAggregate = $sort === 'rating' || isset($filters['rating_min']) || isset($filters['rating_max']);
        $needsJobAggregate = in_array($sort, ['completed_jobs', 'active_jobs'], true);

        if ($needsRatingAggregate) {
            $query->leftJoinSub(self::ratingAggregateSubquery(), 'rating_agg', 'rating_agg.technician_id', '=', 'technicians.id');

            if (isset($filters['rating_min'])) {
                $query->where('rating_agg.average_rating', '>=', $filters['rating_min']);
            }

            if (isset($filters['rating_max'])) {
                $query->where('rating_agg.average_rating', '<=', $filters['rating_max']);
            }
        }

        if ($needsJobAggregate) {
            $query->leftJoinSub(self::jobAggregateSubquery(), 'job_agg', 'job_agg.technician_id', '=', 'technicians.id');
        }

        $total = (clone $query)->count('technicians.id');
        $lastPage = max((int) ceil($total / $perPage), 1);

        match ($sort) {
            'newest' => $query->orderByDesc('technicians.created_at'),
            'rating' => $query->orderByDesc(DB::raw('COALESCE(rating_agg.average_rating, -1)')),
            'completed_jobs' => $query->orderByDesc(DB::raw('COALESCE(job_agg.completed_count, 0)')),
            'active_jobs' => $query->orderByDesc(DB::raw('COALESCE(job_agg.active_count, 0)')),
            default => $query->orderBy('technicians.full_name'),
        };

        $rows = $query
            ->orderBy('technicians.id')
            ->forPage($page, $perPage)
            ->get(['technicians.*', 'technician_statuses.code as status_code', 'technician_statuses.is_assignable as status_is_assignable']);

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

    private static function ratingAggregateSubquery(): Builder
    {
        $exclusiveBookings = DB::table('technician_assignments')
            ->join('booking_items', 'booking_items.id', '=', 'technician_assignments.booking_item_id')
            ->groupBy('booking_items.booking_id')
            ->havingRaw('COUNT(DISTINCT technician_assignments.technician_id) = 1')
            ->select('booking_items.booking_id', DB::raw('MIN(technician_assignments.technician_id) as technician_id'));

        return DB::query()
            ->fromSub($exclusiveBookings, 'sole')
            ->join('ratings', 'ratings.booking_id', '=', 'sole.booking_id')
            ->groupBy('sole.technician_id')
            ->select([
                'sole.technician_id',
                DB::raw('AVG(ratings.rating_value) as average_rating'),
                DB::raw('COUNT(*) as rating_count'),
            ]);
    }

    private static function jobAggregateSubquery(): Builder
    {
        return DB::table('technician_assignments')
            ->join('booking_items', 'booking_items.id', '=', 'technician_assignments.booking_item_id')
            ->join('booking_item_statuses', 'booking_item_statuses.id', '=', 'booking_items.status_id')
            ->groupBy('technician_assignments.technician_id')
            ->select([
                'technician_assignments.technician_id',
                DB::raw("SUM(CASE WHEN technician_assignments.released_at IS NULL AND booking_item_statuses.code IN ('ASSIGNED', 'IN_PROGRESS') THEN 1 ELSE 0 END) as active_count"),
                DB::raw('SUM(CASE WHEN technician_assignments.released_at IS NULL AND booking_items.completed_at IS NOT NULL THEN 1 ELSE 0 END) as completed_count'),
            ]);
    }
}
