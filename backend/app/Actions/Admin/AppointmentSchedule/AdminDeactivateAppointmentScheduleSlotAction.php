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
 * BLUE V1 Phase B27 (Appointment Schedule Management). Closing a slot
 * (`is_active = 0`) only stops NEW customer holds from being created
 * against it (App\Actions\Checkout\CreateAppointmentHoldAction /
 * App\Actions\Admin\Booking\AdminRescheduleBookingAction both already
 * require `appointment_slots.is_active = 1`) - it NEVER cancels any
 * existing Booking, and never touches `appointment_holds` at all. When the
 * slot already has occupancy, the response carries a `warning` string the
 * Admin UI must surface before/alongside the confirmation, per BLUE V1's
 * explicit safety requirement - the mutation itself still succeeds; this
 * is an informational warning, not a block.
 */
final class AdminDeactivateAppointmentScheduleSlotAction
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

            $bookingsCount = DB::table('bookings')
                ->join('booking_statuses', 'booking_statuses.id', '=', 'bookings.status_id')
                ->where('bookings.appointment_slot_id', $slotIdBinary)
                ->where('booking_statuses.code', '!=', 'CANCELLED')
                ->count();

            if ((int) $slot->is_active === 0) {
                return $this->respondWithSlot($slotIdBinary, $occupied, 'Appointment slot is already closed.', $bookingsCount);
            }

            DB::table('appointment_slots')->where('id', $slotIdBinary)->update(['is_active' => 0, 'updated_at' => $now]);

            AdminAuditLogger::record($request, $actor, 'APPOINTMENT_SLOT_DEACTIVATED', 'APPOINTMENT_SLOT', $slotUuid);

            return $this->respondWithSlot($slotIdBinary, $occupied, 'Appointment slot closed successfully.', $bookingsCount);
        });
    }

    private function respondWithSlot(string $slotIdBinary, int $occupied, string $message, int $bookingsCount): array
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

        $data = ['appointment_slot' => AdminAppointmentSchedulePresenter::presentSlot($row, $occupied, 0)];

        if ($bookingsCount > 0) {
            $data['warning'] = "Closing this slot prevents new bookings but does not cancel the {$bookingsCount} existing booking(s) already linked to it.";
        }

        return $this->ok(200, $message, $data);
    }
}
