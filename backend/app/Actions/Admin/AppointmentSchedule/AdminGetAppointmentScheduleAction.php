<?php

namespace App\Actions\Admin\AppointmentSchedule;

use App\Support\Admin\AdminAppointmentSchedulePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Checkout\AppointmentScheduleDate;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B27 (Appointment Schedule Management). Full-day Admin
 * schedule view - unlike App\Support\Checkout\AppointmentSlotAvailability
 * (which only ever returns bookable, future, active slots for the
 * checkout/reschedule picker), this returns EVERY `appointment_slots` row
 * for the selected Dubai calendar date regardless of `is_active` or
 * whether it has already passed - an Admin needs to see CLOSED and FULL
 * slots too, and to review the day's history. Occupied-capacity still
 * reuses the exact same App\Support\Checkout\AppointmentSlotOccupancy
 * predicate the checkout engine uses (via AdminAppointmentSchedulePresenter),
 * never a second calculation.
 */
final class AdminGetAppointmentScheduleAction
{
    use BuildsCartResult;

    public function handle(string $date): array
    {
        $range = AppointmentScheduleDate::utcDayRange($date);

        if ($range === null) {
            return $this->unprocessable('The given data was invalid.', ['date' => ['The date must be a real calendar date in Y-m-d format.']]);
        }

        $slots = DB::table('appointment_slots')
            ->join('appointment_time_windows', 'appointment_time_windows.id', '=', 'appointment_slots.time_window_id')
            ->where('appointment_slots.starts_at', '>=', $range['from'])
            ->where('appointment_slots.starts_at', '<', $range['to'])
            ->orderBy('appointment_slots.starts_at')
            ->get([
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

        return $this->ok(200, 'Appointment schedule retrieved successfully.', [
            'date' => $date,
            'timezone' => AppointmentScheduleDate::timezone(),
            'appointment_slots' => AdminAppointmentSchedulePresenter::presentDay($slots, now()),
        ]);
    }
}
