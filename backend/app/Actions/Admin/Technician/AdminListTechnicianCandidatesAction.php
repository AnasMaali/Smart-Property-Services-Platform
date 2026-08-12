<?php

namespace App\Actions\Admin\Technician;

use App\Support\Admin\AdminTechnicianPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only, server-authoritative candidate list for assigning a Technician
 * to one Booking Item (BLUE V1 Phase 9B) - resolves compatibility from
 * `booking_item -> service -> service_specializations` intersected with
 * `technician_specializations`, exactly like
 * App\Actions\Technician\AssignTechnicianToBookingItemAction itself, so
 * Flutter/the Admin UI never has to (re-)implement that matching logic.
 *
 * This is advisory only. `is_double_booked` reflects a point-in-time overlap
 * check against the Booking's appointment slot, and the whole list can go
 * stale between this read and the actual assign/reassign call - it is never
 * treated as a reservation or a guarantee. `AssignTechnicianToBookingItemAction`
 * re-validates every eligibility rule itself, under a row lock, at write
 * time - this class never bypasses that.
 */
final class AdminListTechnicianCandidatesAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $bookingItemUuid): array
    {
        try {
            $itemIdBinary = UuidBinary::toBinary($bookingItemUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Booking Item not found.');
        }

        $item = DB::table('booking_items')
            ->join('booking_item_statuses', 'booking_item_statuses.id', '=', 'booking_items.status_id')
            ->join('services', 'services.id', '=', 'booking_items.service_id')
            ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
            ->join('appointment_slots', 'appointment_slots.id', '=', 'bookings.appointment_slot_id')
            ->where('booking_items.id', $itemIdBinary)
            ->first([
                'booking_items.id',
                'booking_items.service_id',
                'booking_items.service_code_snapshot',
                'booking_items.service_name_snapshot',
                'booking_item_statuses.code as status_code',
                'bookings.id as booking_id',
                'appointment_slots.starts_at',
                'appointment_slots.ends_at',
            ]);

        if ($item === null) {
            return $this->notFound('Booking Item not found.');
        }

        $requiredSpecializationIds = DB::table('service_specializations')
            ->where('service_id', $item->service_id)
            ->where('is_active', 1)
            ->pluck('specialization_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $itemPayload = [
            'uuid' => UuidBinary::toString($item->id),
            'status' => $item->status_code,
            'service' => [
                'uuid' => UuidBinary::toString($item->service_id),
                'code' => $item->service_code_snapshot,
                'name' => $item->service_name_snapshot,
            ],
        ];

        if ($requiredSpecializationIds === []) {
            return $this->ok(200, 'No specialization is configured for this service yet.', [
                'item' => $itemPayload,
                'requirement_configured' => false,
                'candidates' => [],
            ]);
        }

        $candidateRows = DB::table('technicians')
            ->join('technician_statuses', 'technician_statuses.id', '=', 'technicians.status_id')
            ->whereIn('technicians.id', function ($subQuery) use ($requiredSpecializationIds) {
                $subQuery->select('technician_specializations.technician_id')
                    ->from('technician_specializations')
                    ->whereIn('technician_specializations.specialization_id', $requiredSpecializationIds)
                    ->where('technician_specializations.is_active', 1);
            })
            ->where('technician_statuses.is_assignable', 1)
            ->orderBy('technicians.full_name')
            ->get(['technicians.*', 'technician_statuses.code as status_code']);

        if ($candidateRows->isEmpty()) {
            return $this->ok(200, 'No eligible technicians found.', [
                'item' => $itemPayload,
                'requirement_configured' => true,
                'candidates' => [],
            ]);
        }

        $candidateIds = $candidateRows->pluck('id')->all();

        $doubleBookedIds = DB::table('technician_assignments')
            ->join('booking_items', 'booking_items.id', '=', 'technician_assignments.booking_item_id')
            ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
            ->join('appointment_slots', 'appointment_slots.id', '=', 'bookings.appointment_slot_id')
            ->join('booking_item_statuses', 'booking_item_statuses.id', '=', 'booking_items.status_id')
            ->join('booking_statuses', 'booking_statuses.id', '=', 'bookings.status_id')
            ->whereIn('technician_assignments.technician_id', $candidateIds)
            ->whereNull('technician_assignments.released_at')
            ->where('bookings.id', '!=', $item->booking_id)
            ->where('booking_item_statuses.code', '!=', 'CANCELLED')
            ->where('booking_statuses.code', '!=', 'CANCELLED')
            ->where('appointment_slots.starts_at', '<', $item->ends_at)
            ->where('appointment_slots.ends_at', '>', $item->starts_at)
            ->pluck('technician_assignments.technician_id')
            ->unique()
            ->all();

        $doubleBooked = array_flip($doubleBookedIds);

        $presented = AdminTechnicianPresenter::presentList($candidateRows);

        foreach ($presented as $index => $technician) {
            $presented[$index]['is_double_booked'] = isset($doubleBooked[$candidateRows[$index]->id]);
        }

        return $this->ok(200, 'Technician candidates retrieved successfully.', [
            'item' => $itemPayload,
            'requirement_configured' => true,
            'candidates' => $presented,
        ]);
    }
}
