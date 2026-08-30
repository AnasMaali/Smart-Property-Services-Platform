<?php

namespace App\Actions\Admin\Technician;

use App\Support\Admin\AdminTechnicianPresenter;
use App\Support\Booking\BookingItemStatuses;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Paginated Booking/Job history for one Technician (BLUE V1 Technician
 * Admin Management section 10/14/25) - every `technician_assignments` row
 * ever written for this Technician, newest-assigned-first. Never a second
 * history table - `technician_assignments` itself (assigned_at/released_at/
 * released_by_user_id/release_reason) already is the permanent audit trail
 * (see App\Actions\Technician\AssignTechnicianToBookingItemAction::
 * reassign()'s docblock).
 */
final class AdminListTechnicianJobsAction
{
    use BuildsCartResult;

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    public function handle(string $technicianUuid, int $page, int $perPage): array
    {
        try {
            $technicianIdBinary = UuidBinary::toBinary($technicianUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Technician not found.');
        }

        if (! DB::table('technicians')->where('id', $technicianIdBinary)->exists()) {
            return $this->notFound('Technician not found.');
        }

        $page = max($page, 1);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        $query = DB::table('technician_assignments')
            ->join('booking_items', 'booking_items.id', '=', 'technician_assignments.booking_item_id')
            ->join('booking_item_statuses', 'booking_item_statuses.id', '=', 'booking_items.status_id')
            ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
            ->join('booking_statuses', 'booking_statuses.id', '=', 'bookings.status_id')
            ->join('users as assigned_by', 'assigned_by.id', '=', 'technician_assignments.assigned_by_user_id')
            ->join('user_profiles as assigned_by_profile', 'assigned_by_profile.user_id', '=', 'assigned_by.id')
            ->leftJoin('users as released_by', 'released_by.id', '=', 'technician_assignments.released_by_user_id')
            ->leftJoin('user_profiles as released_by_profile', 'released_by_profile.user_id', '=', 'released_by.id')
            ->where('technician_assignments.technician_id', $technicianIdBinary);

        $total = (clone $query)->count('technician_assignments.id');
        $lastPage = max((int) ceil($total / $perPage), 1);

        $inProgressStatusId = BookingItemStatuses::id('IN_PROGRESS');

        $rows = $query
            ->orderByDesc('technician_assignments.assigned_at')
            ->forPage($page, $perPage)
            ->get([
                'technician_assignments.id as assignment_id',
                'technician_assignments.is_primary',
                'technician_assignments.assigned_at',
                'technician_assignments.released_at',
                'technician_assignments.release_reason',
                'booking_items.id as booking_item_id',
                'booking_items.service_name_snapshot',
                'booking_items.completed_at',
                'booking_items.cancelled_at',
                'booking_item_statuses.code as item_status',
                'bookings.id as booking_id',
                'bookings.booking_number',
                'booking_statuses.code as booking_status',
                'assigned_by_profile.full_name as assigned_by_name',
                'released_by_profile.full_name as released_by_name',
            ]);

        $itemIds = $rows->pluck('booking_item_id')->unique()->all();

        $startedAtByItem = $itemIds === [] ? collect() : DB::table('booking_item_status_history')
            ->whereIn('booking_item_id', $itemIds)
            ->where('to_status_id', $inProgressStatusId)
            ->selectRaw('booking_item_id, MIN(changed_at) as started_at')
            ->groupBy('booking_item_id')
            ->get()
            ->keyBy('booking_item_id');

        $rows = $rows->map(function ($row) use ($startedAtByItem) {
            $row->started_at = $startedAtByItem->get($row->booking_item_id)?->started_at;

            return $row;
        });

        return $this->ok(200, 'Technician job history retrieved successfully.', [
            'jobs' => AdminTechnicianPresenter::presentJobs($rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }
}
