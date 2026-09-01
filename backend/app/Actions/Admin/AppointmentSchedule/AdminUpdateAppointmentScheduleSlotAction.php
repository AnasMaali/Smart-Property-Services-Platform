<?php

namespace App\Actions\Admin\AppointmentSchedule;

use App\Models\User;
use App\Support\Admin\AdminAppointmentSchedulePresenter;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Checkout\AppointmentSlotOccupancy;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BLUE V1 Phase B27 (Appointment Schedule Management). Admin may safely
 * change `booking_capacity` and `internal_note` on an already-generated
 * dated slot. `starts_at`/`ends_at`/`time_window_id` are never accepted
 * here at all (see UpdateAdminAppointmentScheduleSlotRequest's docblock) -
 * there is no code path in this Action that could silently move a
 * historical customer's appointment.
 *
 * CRITICAL SAFETY RULE: `booking_capacity` may never drop below the slot's
 * CURRENT occupied count (App\Support\Checkout\AppointmentSlotOccupancy -
 * the exact same predicate checkout's own capacity engine uses, computed
 * under the same row lock as the write, never a stale read). Occupied ==
 * new capacity is allowed (the slot simply becomes FULL); occupied >
 * new capacity is rejected with 409, never silently clamped or allowed to
 * create over-capacity going forward.
 */
final class AdminUpdateAppointmentScheduleSlotAction
{
    use BuildsCartResult;

    /**
     * @param  array{booking_capacity: int, internal_note: ?string}  $data
     */
    public function handle(Request $request, string $slotUuid, User $actor, array $data): array
    {
        try {
            $slotIdBinary = UuidBinary::toBinary($slotUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Appointment slot not found.');
        }

        return DB::transaction(function () use ($request, $slotIdBinary, $slotUuid, $actor, $data): array {
            $slot = DB::table('appointment_slots')->where('id', $slotIdBinary)->lockForUpdate()->first();

            if ($slot === null) {
                return $this->notFound('Appointment slot not found.');
            }

            $now = now();

            $occupied = AppointmentSlotOccupancy::query($now)
                ->where('appointment_slot_id', $slotIdBinary)
                ->lockForUpdate()
                ->count();

            if ($data['booking_capacity'] < $occupied) {
                return $this->unprocessable(
                    'The given data was invalid.',
                    ['booking_capacity' => ["Capacity cannot be set below the {$occupied} reservation(s) currently occupying this slot."]]
                );
            }

            DB::table('appointment_slots')->where('id', $slotIdBinary)->update([
                'booking_capacity' => $data['booking_capacity'],
                'internal_note' => $data['internal_note'],
                'updated_at' => $now,
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'APPOINTMENT_SLOT_UPDATED',
                'APPOINTMENT_SLOT',
                $slotUuid,
                ['booking_capacity' => $data['booking_capacity']],
                ['booking_capacity' => (int) $slot->booking_capacity],
            );

            return $this->respondWithSlot($slotIdBinary, $occupied);
        });
    }

    private function respondWithSlot(string $slotIdBinary, int $occupied): array
    {
        $row = DB::table('appointment_slots')
            ->join('appointment_time_windows', 'appointment_time_windows.id', '=', 'appointment_slots.time_window_id')
            ->where('appointment_slots.id', $slotIdBinary)
            ->first([
                'appointment_slots.id',
                'appointment_slots.starts_at',
                'appointment_slots.ends_at',
                'appointment_slots.booking_capacity',
                'appointment_slots.is_active',
                'appointment_slots.internal_note',
                'appointment_slots.time_window_id',
                'appointment_slots.created_at',
                'appointment_slots.updated_at',
                'appointment_time_windows.code as window_code',
                'appointment_time_windows.name as window_name',
            ]);

        return $this->ok(200, 'Appointment slot updated successfully.', [
            'appointment_slot' => AdminAppointmentSchedulePresenter::presentSlot($row, $occupied, 0),
        ]);
    }
}
