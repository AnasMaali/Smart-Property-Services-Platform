<?php

namespace App\Actions\Checkout;

use App\Models\Cart;
use App\Support\Cart\CartStatuses;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only availability list. appointment_slots carries no service/zone
 * dimension in the schema (no service_id or area_id column), so BLUE V1
 * cannot scope slots to "this cart's services" beyond what the table
 * itself already represents - every currently bookable slot is returned
 * identically regardless of cart contents. A slot is bookable when it is
 * active, still in the future, has an active appointment_time_windows row,
 * and has remaining capacity once every currently-occupying hold
 * (converted, or unexpired and unreleased) is counted - no capacity/
 * duration/staffing rule is invented here.
 */
class GetAppointmentSlotsAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $userUuid): array
    {
        $cart = Cart::where('customer_user_id', UuidBinary::toBinary($userUuid))
            ->where('status_id', CartStatuses::id('ACTIVE'))
            ->first();

        if ($cart === null) {
            return $this->notFound('No active cart to check out.');
        }

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
            return $this->ok(200, 'Appointment slots retrieved successfully.', ['appointment_slots' => []]);
        }

        $occupiedBySlot = DB::table('appointment_holds')
            ->whereIn('appointment_slot_id', $slots->pluck('id'))
            ->whereNull('released_at')
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

        return $this->ok(200, 'Appointment slots retrieved successfully.', ['appointment_slots' => $payload]);
    }
}
