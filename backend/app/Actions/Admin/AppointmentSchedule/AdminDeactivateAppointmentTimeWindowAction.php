<?php

namespace App\Actions\Admin\AppointmentSchedule;

use App\Models\User;
use App\Support\Admin\AdminAppointmentTimeWindowPresenter;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B27 (Appointment Schedule Management). Deactivating a
 * template only stops it from being used by future
 * AdminGenerateAppointmentScheduleAction runs - it never touches any
 * already-generated `appointment_slots` row (their FK to this template is
 * ON DELETE RESTRICT, but deactivation is not deletion, and this Action
 * never deletes a template - preserving history is a hard requirement).
 * Already-inactive is a safe idempotent no-op.
 */
final class AdminDeactivateAppointmentTimeWindowAction
{
    use BuildsCartResult;

    public function handle(Request $request, string $windowId, User $actor): array
    {
        if (! ctype_digit($windowId)) {
            return $this->notFound('Appointment time window not found.');
        }

        return DB::transaction(function () use ($request, $windowId, $actor): array {
            $window = DB::table('appointment_time_windows')->where('id', (int) $windowId)->lockForUpdate()->first();

            if ($window === null) {
                return $this->notFound('Appointment time window not found.');
            }

            if ((int) $window->is_active === 0) {
                return $this->ok(200, 'Appointment time window is already inactive.', ['appointment_time_window' => AdminAppointmentTimeWindowPresenter::present($window)]);
            }

            DB::table('appointment_time_windows')->where('id', $window->id)->update(['is_active' => 0, 'updated_at' => now()]);

            AdminAuditLogger::record($request, $actor, 'APPOINTMENT_TIME_WINDOW_DEACTIVATED', 'APPOINTMENT_TIME_WINDOW', (string) $window->id);

            $updated = DB::table('appointment_time_windows')->where('id', $window->id)->first();

            return $this->ok(200, 'Appointment time window deactivated successfully.', ['appointment_time_window' => AdminAppointmentTimeWindowPresenter::present($updated)]);
        });
    }
}
