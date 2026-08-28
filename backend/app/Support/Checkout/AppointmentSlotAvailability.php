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

        $occupiedBySlot = DB::table('appointment_holds')
            ->whereIn('appointment_slot_id', $slots->pluck('id'))
            ->whereNull('released_at')
            ->whereNull('superseded_at')
            ->where(function ($query) use ($now) {
                $query->whereNotNull('converted_at')->orWhere('expires_at', '>', $now);
            })
            ->select('appointment_slot_id', DB::raw('COUNT(*) as occupied'))
            ->groupBy('appointment_slot_id')
            ->pluck('occupied', 'appointment_slot_id');

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
}
