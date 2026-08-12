<?php

namespace App\Support\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one safe `technician_assignments` row shape returned by every
 * Admin assignment-mutating endpoint (BLUE V1 Phase 9B: assign, reassign,
 * start work, complete work) - a single, consistent contract so the Admin
 * client never has to parse a different assignment shape per action.
 */
final class AdminAssignmentPresenter
{
    /**
     * @return array<string, mixed>|null
     */
    public static function present(string $assignmentUuid): ?array
    {
        $row = DB::table('technician_assignments')
            ->join('technicians', 'technicians.id', '=', 'technician_assignments.technician_id')
            ->join('specializations', 'specializations.id', '=', 'technician_assignments.specialization_id')
            ->where('technician_assignments.id', UuidBinary::toBinary($assignmentUuid))
            ->first([
                'technician_assignments.id',
                'technician_assignments.booking_item_id',
                'technician_assignments.assigned_at',
                'technician_assignments.released_at',
                'technician_assignments.internal_note',
                'technicians.id as technician_id',
                'technicians.full_name as technician_full_name',
                'technicians.phone_number as technician_phone_number',
                'specializations.code as specialization_code',
                'specializations.name as specialization_name',
            ]);

        if ($row === null) {
            return null;
        }

        return [
            'uuid' => UuidBinary::toString($row->id),
            'booking_item_uuid' => UuidBinary::toString($row->booking_item_id),
            'technician' => [
                'uuid' => UuidBinary::toString($row->technician_id),
                'full_name' => $row->technician_full_name,
                'phone_number' => $row->technician_phone_number,
            ],
            'specialization' => [
                'code' => $row->specialization_code,
                'name' => $row->specialization_name,
            ],
            'assigned_at' => Carbon::parse($row->assigned_at)->toIso8601String(),
            'internal_note' => $row->internal_note,
        ];
    }
}
