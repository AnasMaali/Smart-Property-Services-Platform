<?php

namespace App\Actions\Admin\AppointmentSchedule;

use App\Models\User;
use App\Support\Admin\AdminAppointmentTimeWindowPresenter;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B27 (Appointment Schedule Management). Activating a
 * template makes it eligible for future App\Actions\Admin\
 * AppointmentSchedule\AdminGenerateAppointmentScheduleAction runs -
 * already-generated `appointment_slots` rows are entirely unaffected
 * either way (their own `is_active` is independent - see
 * App\Actions\Admin\AppointmentSchedule\AdminActivateAppointmentScheduleSlotAction).
 * Already-active is a safe idempotent no-op, mirroring
 * App\Actions\Admin\Service\AdminActivateServiceCategoryAction.
 */
final class AdminActivateAppointmentTimeWindowAction
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

            if ((int) $window->is_active === 1) {
                return $this->ok(200, 'Appointment time window is already active.', ['appointment_time_window' => AdminAppointmentTimeWindowPresenter::present($window)]);
            }

            DB::table('appointment_time_windows')->where('id', $window->id)->update(['is_active' => 1, 'updated_at' => now()]);

            AdminAuditLogger::record($request, $actor, 'APPOINTMENT_TIME_WINDOW_ACTIVATED', 'APPOINTMENT_TIME_WINDOW', (string) $window->id);

            $updated = DB::table('appointment_time_windows')->where('id', $window->id)->first();

            return $this->ok(200, 'Appointment time window activated successfully.', ['appointment_time_window' => AdminAppointmentTimeWindowPresenter::present($updated)]);
        });
    }
}
