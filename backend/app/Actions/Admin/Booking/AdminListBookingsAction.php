<?php

namespace App\Actions\Admin\Booking;

use App\Support\Admin\AdminBookingPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only, paginated Booking listing for Admin operators (BLUE V1 Phase
 * 9B) - unlike App\Actions\Booking\ListBookingsAction, never scoped to one
 * customer (docs/05-system-requirements/04-role-and-access-control-requirements.md
 * ACR-BKG-06: "The Admin may view and manage paid Bookings"). Deterministic
 * ordering (`created_at DESC, id DESC`) and a bounded page size make this
 * safe to call against an unbounded Booking table - never returns the whole
 * table in one response.
 */
final class AdminListBookingsAction
{
    use BuildsCartResult;

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{status?: string, booking_number?: string, customer_uuid?: string, technician_uuid?: string, service_uuid?: string, assignment_state?: string, from?: string, to?: string, appointment_date?: string}  $filters
     * @return array<string, mixed>
     */
    public function handle(array $filters, int $page, int $perPage): array
    {
        $page = max($page, 1);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        foreach (['customer_uuid', 'technician_uuid', 'service_uuid'] as $uuidFilter) {
            if (! isset($filters[$uuidFilter])) {
                continue;
            }

            try {
                $filters[$uuidFilter] = UuidBinary::toBinary($filters[$uuidFilter]);
            } catch (InvalidArgumentException) {
                return $this->ok(200, 'Bookings retrieved successfully.', [
                    'bookings' => [],
                    'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1],
                ]);
            }
        }

        $query = DB::table('bookings')
            ->join('carts', 'carts.id', '=', 'bookings.cart_id')
            ->join('booking_statuses', 'booking_statuses.id', '=', 'bookings.status_id');

        if (isset($filters['status'])) {
            $query->where('booking_statuses.code', $filters['status']);
        }

        if (isset($filters['booking_number'])) {
            $query->where('bookings.booking_number', $filters['booking_number']);
        }

        if (isset($filters['customer_uuid'])) {
            $query->where('carts.customer_user_id', $filters['customer_uuid']);
        }

        if (isset($filters['from'])) {
            $query->where('bookings.created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('bookings.created_at', '<=', $filters['to']);
        }

        if (isset($filters['appointment_date'])) {
            $query->join('appointment_slots', 'appointment_slots.id', '=', 'bookings.appointment_slot_id')
                ->whereDate('appointment_slots.starts_at', $filters['appointment_date']);
        }

        if (isset($filters['service_uuid'])) {
            $query->whereExists(function ($sub) use ($filters) {
                $sub->select(DB::raw(1))
                    ->from('booking_items')
                    ->whereColumn('booking_items.booking_id', 'bookings.id')
                    ->where('booking_items.service_id', $filters['service_uuid']);
            });
        }

        if (isset($filters['technician_uuid'])) {
            $query->whereExists(function ($sub) use ($filters) {
                $sub->select(DB::raw(1))
                    ->from('booking_items')
                    ->join('technician_assignments', 'technician_assignments.booking_item_id', '=', 'booking_items.id')
                    ->whereColumn('booking_items.booking_id', 'bookings.id')
                    ->where('technician_assignments.technician_id', $filters['technician_uuid']);
            });
        }

        if (isset($filters['assignment_state'])) {
            $assignmentSummary = DB::table('booking_items')
                ->selectRaw('booking_id, COUNT(*) as items_count, SUM(CASE WHEN EXISTS (
                        SELECT 1 FROM technician_assignments
                        WHERE technician_assignments.booking_item_id = booking_items.id
                        AND technician_assignments.released_at IS NULL
                    ) THEN 1 ELSE 0 END) as assigned_count')
                ->groupBy('booking_id');

            $query->joinSub($assignmentSummary, 'assignment_summary', 'assignment_summary.booking_id', '=', 'bookings.id');

            match ($filters['assignment_state']) {
                'PENDING' => $query->where('assignment_summary.assigned_count', 0),
                'FULL' => $query->where('assignment_summary.items_count', '>', 0)
                    ->whereColumn('assignment_summary.assigned_count', '=', 'assignment_summary.items_count'),
                'PARTIAL' => $query->where('assignment_summary.assigned_count', '>', 0)
                    ->whereColumn('assignment_summary.assigned_count', '<', 'assignment_summary.items_count'),
            };
        }

        $total = (clone $query)->count('bookings.id');
        $lastPage = max((int) ceil($total / $perPage), 1);

        $rows = $query
            ->orderByDesc('bookings.created_at')
            ->orderByDesc('bookings.id')
            ->forPage($page, $perPage)
            ->get(['bookings.*', 'carts.customer_user_id', 'carts.currency_id as cart_currency_id']);

        return $this->ok(200, 'Bookings retrieved successfully.', [
            'bookings' => AdminBookingPresenter::presentList($rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }
}
