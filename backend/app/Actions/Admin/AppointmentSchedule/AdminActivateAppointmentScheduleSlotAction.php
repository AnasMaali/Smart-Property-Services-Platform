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
 * BLUE V1 Phase B27 (Appointment Schedule Management). Reopening a slot
 * restores its normal availability, subject to its existing capacity/
 * occupancy - a reopened slot that is already at (or over) capacity from
 * historical occupancy simply reports FULL again immediately, exactly like
 * any other slot; reopening never adjusts capacity to "make room".
 */
final class AdminActivateAppointmentScheduleSlotAction
{
    use BuildsCartResult;

    public function handle(Request $request, string $slotUuid, User $actor): array
    {
        try {
            $slotIdBinary = UuidBinary::toBinary($slotUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Appointment slot not found.');
        }

        return DB::transaction(function () use ($request, $slotIdBinary, $slotUuid, $actor): array {
            $slot = DB::table('appointment_slots')->where('id', $slotIdBinary)->lockForUpdate()->first();

            if ($slot === null) {
                return $this->notFound('Appointment slot not found.');
            }

            $now = now();
            $occupied = (int) (AppointmentSlotOccupancy::countBySlot([$slotIdBinary], $now)[$slotIdBinary] ?? 0);

            if ((int) $slot->is_active === 1) {
                return $this->respondWithSlot($slotIdBinary, $occupied, 'Appointment slot is already active.');
            }

            DB::table('appointment_slots')->where('id', $slotIdBinary)->update(['is_active' => 1, 'updated_at' => $now]);

            AdminAuditLogger::record($request, $actor, 'APPOINTMENT_SLOT_ACTIVATED', 'APPOINTMENT_SLOT', $slotUuid);

            return $this->respondWithSlot($slotIdBinary, $occupied, 'Appointment slot reopened successfully.');
        });
    }

    private function respondWithSlot(string $slotIdBinary, int $occupied, string $message): array
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

        return $this->ok(200, $message, ['appointment_slot' => AdminAppointmentSchedulePresenter::presentSlot($row, $occupied, 0)]);
    }
}
