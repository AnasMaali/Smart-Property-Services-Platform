<?php

namespace App\Actions\Admin\AppointmentSchedule;

use App\Support\Admin\AdminAppointmentSchedulePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Checkout\AppointmentSlotOccupancy;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BLUE V1 Phase B27 (Appointment Schedule Management). One dated slot's
 * full Admin detail - capacity/occupancy, the Bookings actually occupying
 * it (App\Support\Admin\AdminBookingPresenter, unchanged), and safe,
 * non-identifying visibility into its currently-open temporary holds.
 */
final class AdminGetAppointmentScheduleSlotAction
{
    use BuildsCartResult;

    public function handle(string $slotUuid): array
    {
        try {
            $slotIdBinary = UuidBinary::toBinary($slotUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Appointment slot not found.');
        }

        $slot = DB::table('appointment_slots')
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

        if ($slot === null) {
            return $this->notFound('Appointment slot not found.');
        }

        $now = now();
        $occupied = (int) (AppointmentSlotOccupancy::countBySlot([$slotIdBinary], $now)[$slotIdBinary] ?? 0);

        return $this->ok(200, 'Appointment slot retrieved successfully.', [
            'appointment_slot' => AdminAppointmentSchedulePresenter::presentSlotDetail($slot, $occupied),
        ]);
    }
}
