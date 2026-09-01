<?php

namespace App\Support\Checkout;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Extracted from App\Actions\Checkout\GetAppointmentSlotsAction (BLUE V1
 * Phase B19) so App\Actions\Admin\Booking\AdminListAppointmentSlotsAction
 * (Admin Reschedule Booking's slot picker) can reuse the exact same
 * bookable-slot/capacity computation, never a second, divergent one. A slot
 * is bookable when it is active, still in the future, has an active
 * appointment_time_windows row, and has remaining capacity once every
 * currently-occupying hold (converted, or unexpired and unreleased) is
 * counted - no capacity/duration/staffing rule is invented here, and none of
 * this depends on a customer Cart.
 */
final class AppointmentSlotAvailability
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function bookableSlots(): array
    {
        $now = now();

        $slots = DB::table('appointment_slots')
            ->join('appointment_time_windows', 'appointment_time_windows.id', '=', 'appointment_slots.time_window_id')
            ->where('appointment_slots.is_active', 1)
            ->where('appointment_time_windows.is_active', 1)
            ->where('appointment_slots.starts_at', '>', $now)
            ->orderBy('appointment_slots.starts_at')
            ->get([
                'appointment_slots.id',
                'appointment_slots.starts_at',
                'appointment_slots.ends_at',
                'appointment_slots.booking_capacity',
                'appointment_time_windows.code as time_window_code',
                'appointment_time_windows.name as time_window_name',
            ]);

        if ($slots->isEmpty()) {
            return [];
        }

        $occupiedBySlot = AppointmentSlotOccupancy::countBySlot($slots->pluck('id')->all(), $now);

        $payload = [];

        foreach ($slots as $slot) {
            $occupied = (int) ($occupiedBySlot[$slot->id] ?? 0);
            $remaining = (int) $slot->booking_capacity - $occupied;

            if ($remaining <= 0) {
                continue;
            }

            $payload[] = [
                'uuid' => UuidBinary::toString($slot->id),
                'starts_at' => Carbon::parse($slot->starts_at)->toIso8601String(),
                'ends_at' => Carbon::parse($slot->ends_at)->toIso8601String(),
                'remaining_capacity' => $remaining,
                'time_window' => [
                    'code' => $slot->time_window_code,
                    'name' => $slot->time_window_name,
                ],
            ];
        }

        return $payload;
    }

    /**
     * BLUE V1 Phase B27 (Appointment Schedule Management). Full-day
     * schedule for one Dubai calendar date - unlike bookableSlots() above,
     * this INCLUDES full/closed slots (so Flutter can render them disabled
     * rather than simply omit them) and does not require a future
     * `starts_at`, since a past date is still a valid, informational
     * lookup. Never synthesizes a placeholder for a time window with no
     * generated `appointment_slots` row for this date - only real,
     * already-generated rows are ever returned. `occupied_capacity` reuses
     * the exact same App\Support\Checkout\AppointmentSlotOccupancy
     * predicate bookableSlots() uses, never a second calculation.
     *
     * @return array<int, array<string, mixed>>|null null when `$date` is not a valid Y-m-d calendar date
     */
    public function slotsForDate(string $date): ?array
    {
        $range = AppointmentScheduleDate::utcDayRange($date);

        if ($range === null) {
            return null;
        }

        $now = now();

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
                'appointment_time_windows.code as time_window_code',
                'appointment_time_windows.name as time_window_name',
            ]);

        if ($slots->isEmpty()) {
            return [];
        }

        $occupiedBySlot = AppointmentSlotOccupancy::countBySlot($slots->pluck('id')->all(), $now);

        return $slots->map(function ($slot) use ($occupiedBySlot) {
            $capacity = (int) $slot->booking_capacity;
            $occupied = (int) ($occupiedBySlot[$slot->id] ?? 0);
            $remaining = max($capacity - $occupied, 0);
            $isActive = (bool) $slot->is_active;
            $isAvailable = $isActive && $remaining > 0;

            $status = match (true) {
                ! $isActive => 'CLOSED',
                $remaining <= 0 => 'FULL',
                default => 'AVAILABLE',
            };

            return [
                'uuid' => UuidBinary::toString($slot->id),
                'starts_at' => Carbon::parse($slot->starts_at)->toIso8601String(),
                'ends_at' => Carbon::parse($slot->ends_at)->toIso8601String(),
                'booking_capacity' => $capacity,
                'occupied_capacity' => $occupied,
                'remaining_capacity' => $remaining,
                'is_available' => $isAvailable,
                'availability_status' => $status,
                'time_window' => [
                    'code' => $slot->time_window_code,
                    'name' => $slot->time_window_name,
                ],
            ];
        })->values()->all();
    }
}
