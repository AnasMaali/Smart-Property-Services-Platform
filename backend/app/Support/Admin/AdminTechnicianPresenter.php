<?php

namespace App\Support\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Admin-facing Technician JSON shape (BLUE V1 Phase 9B, extended by
 * BLUE V1 Technician Admin Management) -
 * docs/03-features-and-requirements/07-technician-assignment.md "Technician
 * Information": name, specialization, availability (status), contact
 * number, current assignment status. The Admin sees the full record
 * (docs/05-system-requirements/04-role-and-access-control-requirements.md
 * "The Admin may access full technician records required for assignment") -
 * unlike the customer-facing contract (not built by any phase yet),
 * `is_phone_visible_to_customer` never gates what an authenticated Admin is
 * shown here, only what a future customer-facing surface would show.
 *
 * Performance metrics (completed_jobs/active_jobs/in_progress_jobs/
 * average_rating/rating_count) are always derived from `technician_
 * assignments`/`booking_items`/`ratings` at read time - never a stored
 * counter - and are always batch-loaded across every Technician on the
 * page in a fixed number of queries, never one query per Technician.
 *
 * Rating attribution: `ratings` is one row per Booking (database/
 * blue_v1_schema.sql, PRIMARY KEY (booking_id)), not per Booking Item or
 * per Technician - docs/03-features-and-requirements/10-rating-and-
 * feedback.md is explicit that "the customer can provide one overall
 * rating for the full booking" and that per-technician rating is deferred
 * to "a future version". A rating is only counted toward a Technician's
 * average_rating/rating_count when that Technician is the ONLY Technician
 * who was ever assigned to ANY Booking Item of that Booking (see
 * ratingAttribution()) - the one case current data can prove without
 * guessing. Ratings on a Booking multiple Technicians worked are still
 * visible in listRatings() for transparency, flagged `is_exclusive: false`,
 * but never counted into any single Technician's average.
 */
final class AdminTechnicianPresenter
{
    public static function loadForDetail(string $technicianIdBinary): ?object
    {
        return DB::table('technicians')
            ->join('technician_statuses', 'technician_statuses.id', '=', 'technicians.status_id')
            ->where('technicians.id', $technicianIdBinary)
            ->select([
                'technicians.*',
                'technician_statuses.code as status_code',
                'technician_statuses.name as status_name',
                'technician_statuses.is_assignable as status_is_assignable',
            ])
            ->first();
    }

    /**
     * Batch-loaded Admin Technician list row shape - never issues a query
     * per Technician.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function presentList(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $technicianIds = $rows->pluck('id')->all();

        $specializations = self::loadSpecializations($technicianIds);
        $jobCounts = self::loadJobCounts($technicianIds);
        $ratingSummaries = self::loadRatingSummaries($technicianIds);

        return $rows->map(fn (object $row) => self::payload(
            $row,
            $specializations->get($row->id, collect()),
            $jobCounts->get($row->id),
            $ratingSummaries->get($row->id),
        ))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(object $row): array
    {
        $specializations = self::loadSpecializations([$row->id])->get($row->id, collect());
        $jobCounts = self::loadJobCounts([$row->id])->get($row->id);
        $ratingSummary = self::loadRatingSummaries([$row->id])->get($row->id);

        $payload = self::payload($row, $specializations, $jobCounts, $ratingSummary);
        $payload['current_assignments'] = self::currentAssignments($row->id);

        return $payload;
    }

    /**
     * Current Work: only active (released_at is null), operationally active
     * (booking item ASSIGNED/IN_PROGRESS) primary assignments - BLUE V1
     * Technician Admin Management section 15. Never exposes operational
     * mutation affordances here; the Admin Booking page remains the only
     * place a job is started/completed/reassigned (section 38's "no
     * operational duplication").
     *
     * @return array<int, array<string, mixed>>
     */
    private static function currentAssignments(string $technicianIdBinary): array
    {
        $rows = DB::table('technician_assignments')
            ->join('booking_items', 'booking_items.id', '=', 'technician_assignments.booking_item_id')
            ->join('booking_item_statuses', 'booking_item_statuses.id', '=', 'booking_items.status_id')
            ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
            ->join('appointment_slots', 'appointment_slots.id', '=', 'bookings.appointment_slot_id')
            ->where('technician_assignments.technician_id', $technicianIdBinary)
            ->whereNull('technician_assignments.released_at')
            ->whereIn('booking_item_statuses.code', ['ASSIGNED', 'IN_PROGRESS'])
            ->orderBy('appointment_slots.starts_at')
            ->get([
                'technician_assignments.id as assignment_id',
                'technician_assignments.assigned_at',
                'booking_items.id as booking_item_id',
                'booking_items.service_name_snapshot',
                'booking_item_statuses.code as item_status',
                'bookings.id as booking_id',
                'bookings.booking_number',
                'appointment_slots.starts_at',
                'appointment_slots.ends_at',
            ]);

        return $rows->map(fn ($row) => [
            'assignment_uuid' => UuidBinary::toString($row->assignment_id),
            'booking_uuid' => UuidBinary::toString($row->booking_id),
            'booking_number' => $row->booking_number,
            'booking_item_uuid' => UuidBinary::toString($row->booking_item_id),
            'service_name' => $row->service_name_snapshot,
            'item_status' => $row->item_status,
            'appointment_starts_at' => Carbon::parse($row->starts_at)->toIso8601String(),
            'appointment_ends_at' => Carbon::parse($row->ends_at)->toIso8601String(),
            'assigned_at' => Carbon::parse($row->assigned_at)->toIso8601String(),
        ])->values()->all();
    }

    /**
     * Paginated Booking/Job history (BLUE V1 Technician Admin Management
     * section 10/14/25) - every technician_assignments row for this
     * Technician, oldest-assignment-first descending, regardless of
     * active/released/completed state. `release_reason`/`released_at`
     * expose exactly what App\Actions\Technician\
     * AssignTechnicianToBookingItemAction::reassign() already writes -
     * never a second history table.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function presentJobs(Collection $rows): array
    {
        return $rows->map(function (object $row) {
            return [
                'assignment_uuid' => UuidBinary::toString($row->assignment_id),
                'booking_uuid' => UuidBinary::toString($row->booking_id),
                'booking_number' => $row->booking_number,
                'booking_status' => $row->booking_status,
                'booking_item_uuid' => UuidBinary::toString($row->booking_item_id),
                'service_name' => $row->service_name_snapshot,
                'item_status' => $row->item_status,
                'is_primary' => (bool) $row->is_primary,
                'assigned_at' => Carbon::parse($row->assigned_at)->toIso8601String(),
                'assigned_by' => $row->assigned_by_name,
                'started_at' => $row->started_at === null ? null : Carbon::parse($row->started_at)->toIso8601String(),
                'completed_at' => $row->completed_at === null ? null : Carbon::parse($row->completed_at)->toIso8601String(),
                'cancelled_at' => $row->cancelled_at === null ? null : Carbon::parse($row->cancelled_at)->toIso8601String(),
                'released_at' => $row->released_at === null ? null : Carbon::parse($row->released_at)->toIso8601String(),
                'released_by' => $row->released_by_name,
                'release_reason' => $row->release_reason,
                'credited_as_completed' => $row->released_at === null && $row->item_status === 'COMPLETED',
            ];
        })->values()->all();
    }

    /**
     * Paginated Ratings history (BLUE V1 Technician Admin Management
     * section 13) - every Booking this Technician was ever assigned to
     * (any item, any assignment row) that also has a `ratings` row.
     * `is_exclusive` tells the Admin whether this Technician was the only
     * one who ever worked the Booking - only exclusive ratings feed
     * average_rating/rating_count (see loadRatingSummaries()); a non-
     * exclusive row is still shown so the Admin never loses visibility
     * into a rating, but it is never silently misattributed as "this
     * Technician's" rating.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function presentRatings(Collection $rows): array
    {
        return $rows->map(fn (object $row) => [
            'booking_uuid' => UuidBinary::toString($row->booking_id),
            'booking_number' => $row->booking_number,
            'rating_value' => (int) $row->rating_value,
            'comment' => $row->comment,
            'submitted_at' => Carbon::parse($row->rating_created_at)->toIso8601String(),
            'is_exclusive' => (bool) $row->is_exclusive,
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(object $row, Collection $specializations, ?object $jobCounts, ?object $ratingSummary): array
    {
        return [
            'uuid' => UuidBinary::toString($row->id),
            'employee_code' => $row->employee_code,
            'full_name' => $row->full_name,
            'phone_number' => $row->phone_number,
            'email' => $row->email,
            'status' => $row->status_code,
            'is_assignable' => (bool) $row->status_is_assignable,
            'is_phone_visible_to_customer' => (bool) $row->is_phone_visible_to_customer,
            'internal_note' => $row->internal_note,
            'specializations' => $specializations->map(fn ($specialization) => [
                'id' => (int) $specialization->specialization_id,
                'code' => $specialization->code,
                'name' => $specialization->name,
                'is_primary' => (bool) $specialization->is_primary,
            ])->values()->all(),
            'performance' => [
                'average_rating' => $ratingSummary === null || $ratingSummary->rating_count === 0 ? null : round((float) $ratingSummary->rating_total / $ratingSummary->rating_count, 2),
                'rating_count' => (int) ($ratingSummary->rating_count ?? 0),
                'completed_jobs' => (int) ($jobCounts->completed_count ?? 0),
                'active_jobs' => (int) ($jobCounts->active_count ?? 0),
                'in_progress_jobs' => (int) ($jobCounts->in_progress_count ?? 0),
            ],
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, string>  $technicianIds
     */
    private static function loadSpecializations(array $technicianIds): Collection
    {
        return DB::table('technician_specializations')
            ->join('specializations', 'specializations.id', '=', 'technician_specializations.specialization_id')
            ->whereIn('technician_specializations.technician_id', $technicianIds)
            ->where('technician_specializations.is_active', 1)
            ->orderByDesc('technician_specializations.is_primary')
            ->get([
                'technician_specializations.technician_id',
                'technician_specializations.specialization_id',
                'technician_specializations.is_primary',
                'specializations.code',
                'specializations.name',
            ])
            ->groupBy('technician_id');
    }

    /**
     * Active/in-progress/completed job counts, batched in two grouped
     * queries regardless of how many Technicians are on the page (BLUE V1
     * Technician Admin Management section 24 - "must NOT cause one query
     * per technician").
     *
     * completed_jobs credits a Technician only for a Booking Item where
     * their assignment row was never released (released_at is null) AND
     * the item actually reached COMPLETED - a Technician reassigned away
     * before completion never counts (section 12/29).
     *
     * @param  array<int, string>  $technicianIds
     */
    private static function loadJobCounts(array $technicianIds): Collection
    {
        $active = DB::table('technician_assignments')
            ->join('booking_items', 'booking_items.id', '=', 'technician_assignments.booking_item_id')
            ->join('booking_item_statuses', 'booking_item_statuses.id', '=', 'booking_items.status_id')
            ->whereIn('technician_assignments.technician_id', $technicianIds)
            ->whereNull('technician_assignments.released_at')
            ->whereIn('booking_item_statuses.code', ['ASSIGNED', 'IN_PROGRESS'])
            ->selectRaw('technician_assignments.technician_id, '
                .'COUNT(*) as active_count, '
                ."SUM(CASE WHEN booking_item_statuses.code = 'IN_PROGRESS' THEN 1 ELSE 0 END) as in_progress_count")
            ->groupBy('technician_assignments.technician_id')
            ->get()
            ->keyBy('technician_id');

        $completed = DB::table('technician_assignments')
            ->join('booking_items', 'booking_items.id', '=', 'technician_assignments.booking_item_id')
            ->whereIn('technician_assignments.technician_id', $technicianIds)
            ->whereNull('technician_assignments.released_at')
            ->whereNotNull('booking_items.completed_at')
            ->selectRaw('technician_assignments.technician_id, COUNT(*) as completed_count')
            ->groupBy('technician_assignments.technician_id')
            ->get()
            ->keyBy('technician_id');

        return collect($technicianIds)->mapWithKeys(function ($technicianId) use ($active, $completed) {
            $activeRow = $active->get($technicianId);
            $completedRow = $completed->get($technicianId);

            return [$technicianId => (object) [
                'active_count' => $activeRow->active_count ?? 0,
                'in_progress_count' => $activeRow->in_progress_count ?? 0,
                'completed_count' => $completedRow->completed_count ?? 0,
            ]];
        });
    }

    /**
     * @param  array<int, string>  $technicianIds
     */
    private static function loadRatingSummaries(array $technicianIds): Collection
    {
        $attribution = self::ratingAttribution($technicianIds);

        return $attribution
            ->filter(fn ($row) => $row->is_exclusive && in_array($row->sole_technician_id, $technicianIds, true))
            ->groupBy('sole_technician_id')
            ->map(fn ($rows) => (object) [
                'rating_count' => $rows->count(),
                'rating_total' => $rows->sum('rating_value'),
            ]);
    }

    /**
     * For every Booking any of $technicianIds was ever assigned to (any
     * Booking Item, any assignment row) that also carries a `ratings` row,
     * resolves whether that Booking's technician_assignments rows - across
     * EVERY Booking Item in the Booking, not just $technicianIds's own
     * rows - name exactly one distinct Technician. That is the only case
     * current schema can prove the rating belongs to a single Technician
     * (see this class's docblock); never a heuristic guess.
     *
     * @param  array<int, string>  $technicianIds
     */
    private static function ratingAttribution(array $technicianIds): Collection
    {
        $candidateBookingIds = DB::table('technician_assignments')
            ->join('booking_items', 'booking_items.id', '=', 'technician_assignments.booking_item_id')
            ->whereIn('technician_assignments.technician_id', $technicianIds)
            ->distinct()
            ->pluck('booking_items.booking_id');

        if ($candidateBookingIds->isEmpty()) {
            return collect();
        }

        $bookingTechnicianSets = DB::table('technician_assignments')
            ->join('booking_items', 'booking_items.id', '=', 'technician_assignments.booking_item_id')
            ->whereIn('booking_items.booking_id', $candidateBookingIds)
            ->selectRaw('booking_items.booking_id, '
                .'COUNT(DISTINCT technician_assignments.technician_id) as technician_count, '
                .'MIN(technician_assignments.technician_id) as sole_technician_id')
            ->groupBy('booking_items.booking_id')
            ->get()
            ->keyBy('booking_id');

        return DB::table('ratings')
            ->join('bookings', 'bookings.id', '=', 'ratings.booking_id')
            ->whereIn('ratings.booking_id', $candidateBookingIds)
            ->get([
                'ratings.booking_id',
                'ratings.rating_value',
                'ratings.comment',
                'ratings.created_at as rating_created_at',
                'bookings.booking_number',
            ])
            ->map(function ($rating) use ($bookingTechnicianSets) {
                $set = $bookingTechnicianSets->get($rating->booking_id);
                $isExclusive = $set !== null && (int) $set->technician_count === 1;

                $rating->is_exclusive = $isExclusive;
                $rating->sole_technician_id = $isExclusive ? $set->sole_technician_id : null;

                return $rating;
            });
    }

    /**
     * Backing query for the paginated Ratings history endpoint - every
     * candidate Booking for ONE Technician, with the same exclusivity flag
     * loadRatingSummaries() uses, so the Admin never sees a number here
     * that disagrees with the Technician's own average_rating.
     */
    public static function ratingsForTechnician(string $technicianIdBinary): Collection
    {
        return self::ratingAttribution([$technicianIdBinary])
            ->sortByDesc('rating_created_at')
            ->values();
    }
}
