<?php

namespace App\Support\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Admin-facing Technician JSON shape (BLUE V1 Phase 9B) -
 * docs/03-features-and-requirements/07-technician-assignment.md "Technician
 * Information": name, specialization, availability (status), contact
 * number, current assignment status. The Admin sees the full record
 * (docs/05-system-requirements/04-role-and-access-control-requirements.md
 * "The Admin may access full technician records required for assignment") -
 * unlike the customer-facing contract (not built by any phase yet),
 * `is_phone_visible_to_customer` never gates what an authenticated Admin is
 * shown here, only what a future customer-facing surface would show.
 */
final class AdminTechnicianPresenter
{
    /**
     * Batch-loaded Admin Technician list row shape - never issues a query
     * per Technician.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function presentList(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $technicianIds = $rows->pluck('id')->all();

        $specializations = DB::table('technician_specializations')
            ->join('specializations', 'specializations.id', '=', 'technician_specializations.specialization_id')
            ->whereIn('technician_specializations.technician_id', $technicianIds)
            ->where('technician_specializations.is_active', 1)
            ->orderByDesc('technician_specializations.is_primary')
            ->get(['technician_specializations.technician_id', 'technician_specializations.is_primary', 'specializations.code', 'specializations.name'])
            ->groupBy('technician_id');

        $activeAssignmentCounts = DB::table('technician_assignments')
            ->whereIn('technician_id', $technicianIds)
            ->whereNull('released_at')
            ->selectRaw('technician_id, COUNT(*) as active_count')
            ->groupBy('technician_id')
            ->get()
            ->keyBy('technician_id');

        return $rows->map(fn (object $row) => self::payload($row, $specializations->get($row->id, collect()), (int) ($activeAssignmentCounts->get($row->id)?->active_count ?? 0)))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(object $row): array
    {
        $specializations = DB::table('technician_specializations')
            ->join('specializations', 'specializations.id', '=', 'technician_specializations.specialization_id')
            ->where('technician_specializations.technician_id', $row->id)
            ->where('technician_specializations.is_active', 1)
            ->orderByDesc('technician_specializations.is_primary')
            ->get(['technician_specializations.is_primary', 'specializations.code', 'specializations.name']);

        $activeCount = DB::table('technician_assignments')
            ->where('technician_id', $row->id)
            ->whereNull('released_at')
            ->count();

        return self::payload($row, $specializations, $activeCount);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(object $row, Collection $specializations, int $activeAssignmentCount): array
    {
        return [
            'uuid' => UuidBinary::toString($row->id),
            'employee_code' => $row->employee_code,
            'full_name' => $row->full_name,
            'phone_number' => $row->phone_number,
            'email' => $row->email,
            'status' => $row->status_code,
            'is_phone_visible_to_customer' => (bool) $row->is_phone_visible_to_customer,
            'internal_note' => $row->internal_note,
            'active_assignments_count' => $activeAssignmentCount,
            'specializations' => $specializations->map(fn ($specialization) => [
                'code' => $specialization->code,
                'name' => $specialization->name,
                'is_primary' => (bool) $specialization->is_primary,
            ])->values()->all(),
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
        ];
    }
}
