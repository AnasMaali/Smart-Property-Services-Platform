<?php

namespace App\Actions\Admin\AppointmentSchedule;

use App\Models\User;
use App\Support\Admin\AdminAppointmentTimeWindowPresenter;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B27 (Appointment Schedule Management). Creates one
 * `appointment_time_windows` template. Mirrors App\Actions\Admin\Service\
 * AdminCreateServiceCategoryAction: a duplicate `code` is rejected as a
 * plain validation error, never the raw unique-key constraint violation.
 * `chk_appointment_time_windows_period` (start_time < end_time) is already
 * enforced by CreateAdminAppointmentTimeWindowRequest's `after:start_time`
 * rule - the database CHECK is defense in depth, not the only guard.
 */
final class AdminCreateAppointmentTimeWindowAction
{
    use BuildsCartResult;

    /**
     * @param  array{code: string, name: string, description: ?string, start_time: string, end_time: string, display_order: int, is_active: bool}  $data
     */
    public function handle(Request $request, User $actor, array $data): array
    {
        return DB::transaction(function () use ($request, $actor, $data): array {
            if (DB::table('appointment_time_windows')->where('code', $data['code'])->exists()) {
                return $this->unprocessable('The given data was invalid.', ['code' => ['This time window code is already in use.']]);
            }

            $now = now();

            $windowId = DB::table('appointment_time_windows')->insertGetId([
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'display_order' => $data['display_order'],
                'is_active' => $data['is_active'] ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'APPOINTMENT_TIME_WINDOW_CREATED',
                'APPOINTMENT_TIME_WINDOW',
                (string) $windowId,
                ['code' => $data['code'], 'name' => $data['name'], 'start_time' => $data['start_time'], 'end_time' => $data['end_time'], 'is_active' => $data['is_active']],
            );

            $created = DB::table('appointment_time_windows')->where('id', $windowId)->first();

            return $this->ok(201, 'Appointment time window created successfully.', ['appointment_time_window' => AdminAppointmentTimeWindowPresenter::present($created)]);
        });
    }
}
