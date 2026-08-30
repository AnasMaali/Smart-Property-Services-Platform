<?php

namespace App\Actions\Admin\Technician;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminTechnicianPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BLUE V1 Technician Admin Management - the single entry point that moves
 * a Technician between `technician_statuses` rows (AVAILABLE/BUSY/
 * ON_LEAVE/INACTIVE - database/blue_v1_schema.sql). "Activate" (-> AVAILABLE)
 * and "Deactivate/Archive" (-> INACTIVE) are simply this action called with
 * that target code - never a separate hard-delete path (BLUE V1 Technician
 * Admin Management section 3/19: history must never disappear because a
 * Technician leaves the company).
 *
 * Moving to INACTIVE specifically is rejected with a deterministic 409 when
 * the Technician still holds an operationally active assignment (released_at
 * is null AND the Booking Item is ASSIGNED/IN_PROGRESS) - archiving must
 * never orphan a live job (section 7/31). Every OTHER transition (AVAILABLE
 * <-> BUSY <-> ON_LEAVE, or re-activating from INACTIVE) is unrestricted:
 * BUSY/ON_LEAVE are ordinary operational states that legitimately coexist
 * with an active assignment - only INACTIVE means "removed from the
 * roster". `technician_statuses.is_assignable` alone is not the signal
 * here (BUSY is also non-assignable) - INACTIVE is checked by its stable
 * `code`, exactly like BookingItemStatuses callers already branch on
 * status codes such as 'COMPLETED'/'CANCELLED' throughout this codebase.
 *
 * Never touches `technician_assignments`, `technician_specializations`, or
 * any Booking/rating row - status is the only thing this action writes.
 */
final class AdminSetTechnicianStatusAction
{
    use BuildsCartResult;

    public function handle(Request $request, string $technicianUuid, User $actor, string $statusCode): array
    {
        try {
            $technicianIdBinary = UuidBinary::toBinary($technicianUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Technician not found.');
        }

        return DB::transaction(function () use ($request, $technicianUuid, $technicianIdBinary, $actor, $statusCode): array {
            $technician = DB::table('technicians')
                ->join('technician_statuses', 'technician_statuses.id', '=', 'technicians.status_id')
                ->where('technicians.id', $technicianIdBinary)
                ->lockForUpdate()
                ->first(['technicians.id', 'technicians.status_id', 'technician_statuses.code as current_status_code']);

            if ($technician === null) {
                return $this->notFound('Technician not found.');
            }

            $targetStatus = DB::table('technician_statuses')->where('code', $statusCode)->where('is_active', 1)->first(['id', 'code']);

            if ($targetStatus === null) {
                return $this->unprocessable('The given data was invalid.', ['status' => ['This technician status does not exist.']]);
            }

            if ($targetStatus->code === $technician->current_status_code) {
                return $this->ok(200, 'Technician is already in this status.', ['technician' => AdminTechnicianPresenter::detail(AdminTechnicianPresenter::loadForDetail($technicianIdBinary))]);
            }

            if ($targetStatus->code === 'INACTIVE' && $this->hasActiveOperationalAssignment($technicianIdBinary)) {
                return $this->conflict('This technician has an active or in-progress job. Reassign or release it before deactivating.');
            }

            $now = now();

            DB::table('technicians')->where('id', $technicianIdBinary)->update([
                'status_id' => $targetStatus->id,
                'status_changed_at' => $now,
                'updated_at' => $now,
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'TECHNICIAN_STATUS_CHANGED',
                'TECHNICIAN',
                $technicianUuid,
                ['status' => $targetStatus->code],
                ['status' => $technician->current_status_code],
            );

            return $this->ok(200, 'Technician status updated successfully.', ['technician' => AdminTechnicianPresenter::detail(AdminTechnicianPresenter::loadForDetail($technicianIdBinary))]);
        });
    }

    private function hasActiveOperationalAssignment(string $technicianIdBinary): bool
    {
        return DB::table('technician_assignments')
            ->join('booking_items', 'booking_items.id', '=', 'technician_assignments.booking_item_id')
            ->join('booking_item_statuses', 'booking_item_statuses.id', '=', 'booking_items.status_id')
            ->where('technician_assignments.technician_id', $technicianIdBinary)
            ->whereNull('technician_assignments.released_at')
            ->whereIn('booking_item_statuses.code', ['ASSIGNED', 'IN_PROGRESS'])
            ->exists();
    }
}
