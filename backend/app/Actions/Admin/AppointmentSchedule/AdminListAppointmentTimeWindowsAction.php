<?php

namespace App\Actions\Admin\AppointmentSchedule;

use App\Support\Admin\AdminAppointmentTimeWindowPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B27 (Appointment Schedule Management). Mirrors
 * App\Actions\Admin\Service\AdminListServiceCategoriesAction exactly:
 * Admin sees every template regardless of `is_active` (an operator needs
 * to see what is currently unavailable for future slot generation), and
 * this stays a small, unpaginated list - BLUE V1's six daily windows are
 * the expected steady-state size, with no product requirement for dozens
 * more.
 */
final class AdminListAppointmentTimeWindowsAction
{
    use BuildsCartResult;

    /**
     * @param  array{is_active?: bool}  $filters
     */
    public function handle(array $filters): array
    {
        $query = DB::table('appointment_time_windows');

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', $filters['is_active'] ? 1 : 0);
        }

        $rows = $query->orderBy('display_order')->get();

        return $this->ok(200, 'Appointment time windows retrieved successfully.', [
            'appointment_time_windows' => AdminAppointmentTimeWindowPresenter::presentList($rows),
        ]);
    }
}
