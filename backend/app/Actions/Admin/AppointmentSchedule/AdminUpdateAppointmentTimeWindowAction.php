<?php

namespace App\Actions\Admin\AppointmentSchedule;

use App\Models\User;
use App\Support\Admin\AdminAppointmentTimeWindowPresenter;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B27 (Appointment Schedule Management). Edits only the
 * template's safe display/clock-time metadata (name, description,
 * start_time, end_time, display_order) - mirrors App\Actions\Admin\
 * Service\AdminUpdateServiceCategoryMetadataAction exactly, including
 * never touching `code` or `is_active` here (activate/deactivate are
 * their own dedicated Actions).
 *
 * Editing a template's clock time NEVER rewrites any already-generated
 * `appointment_slots` row - those keep their own persisted `starts_at`/
 * `ends_at` independently (see App\Actions\Admin\AppointmentSchedule\
 * AdminGenerateAppointmentScheduleAction). This is intentional: a template
 * edit only changes what future generation will produce.
 */
final class AdminUpdateAppointmentTimeWindowAction
{
    use BuildsCartResult;

    /**
     * @param  array{name: string, description: ?string, start_time: string, end_time: string, display_order: int}  $data
     */
    public function handle(Request $request, string $windowId, User $actor, array $data): array
    {
        if (! ctype_digit($windowId)) {
            return $this->notFound('Appointment time window not found.');
        }

        return DB::transaction(function () use ($request, $windowId, $actor, $data): array {
            $window = DB::table('appointment_time_windows')->where('id', (int) $windowId)->lockForUpdate()->first();

            if ($window === null) {
                return $this->notFound('Appointment time window not found.');
            }

            DB::table('appointment_time_windows')->where('id', $window->id)->update([
                'name' => $data['name'],
                'description' => $data['description'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'display_order' => $data['display_order'],
                'updated_at' => now(),
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'APPOINTMENT_TIME_WINDOW_UPDATED',
                'APPOINTMENT_TIME_WINDOW',
                (string) $window->id,
                ['name' => $data['name'], 'start_time' => $data['start_time'], 'end_time' => $data['end_time'], 'display_order' => $data['display_order']],
                ['name' => $window->name, 'start_time' => substr((string) $window->start_time, 0, 5), 'end_time' => substr((string) $window->end_time, 0, 5), 'display_order' => (int) $window->display_order],
            );

            $updated = DB::table('appointment_time_windows')->where('id', $window->id)->first();

            return $this->ok(200, 'Appointment time window updated successfully.', ['appointment_time_window' => AdminAppointmentTimeWindowPresenter::present($updated)]);
        });
    }
}
