<?php

namespace App\Support\Admin;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * BLUE V1 Phase B27 (Appointment Schedule Management). `appointment_time_windows.id`
 * is a plain unsigned int, not a binary(16) UUID (see database/blue_v1_schema.sql)
 * - presented as-is, exactly like App\Support\Admin\AdminServiceCategoryPresenter
 * already does for the identically-shaped `service_categories.id`.
 * `start_time`/`end_time` are MySQL TIME columns - PDO returns them as
 * `H:i:s` strings already, never wrapped in a date, so they are presented
 * verbatim rather than round-tripped through Carbon.
 */
final class AdminAppointmentTimeWindowPresenter
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function presentList(Collection $rows): array
    {
        return $rows->map(fn (object $row) => self::present($row))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'description' => $row->description,
            'start_time' => substr((string) $row->start_time, 0, 5),
            'end_time' => substr((string) $row->end_time, 0, 5),
            'display_order' => (int) $row->display_order,
            'is_active' => (bool) $row->is_active,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse($row->updated_at)->toIso8601String(),
        ];
    }
}
