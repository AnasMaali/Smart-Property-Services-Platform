<?php

namespace App\Actions\Admin\Technician;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminTechnicianPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BLUE V1 Technician Admin Management - creates or updates one
 * `technician_specializations` mapping row (upsert on the existing
 * (technician_id, specialization_id) primary key), mirroring
 * App\Actions\Admin\Service\AdminSetServiceSpecializationAction exactly.
 * "Removing" a specialization is calling this again with `is_active:
 * false` - never a hard delete (`fk_technician_specializations_*` are ON
 * DELETE RESTRICT, and every already-committed `technician_assignments`
 * row snapshots its own `specialization_id` at assignment time, so
 * deactivating a mapping here never rewrites history).
 *
 * This changes ONLY what a FUTURE assignment attempt will accept
 * (App\Actions\Technician\AssignTechnicianToBookingItemAction::
 * matchTechnicianSpecialization() reads this table live).
 * `uq_technician_specializations_primary` already enforces "at most one
 * active primary specialization per Technician" at the database level - a
 * second `is_primary: true` row is rejected as a plain 409.
 */
final class AdminSetTechnicianSpecializationAction
{
    use BuildsCartResult;

    public function handle(Request $request, string $technicianUuid, User $actor, int $specializationId, bool $isPrimary, bool $isActive): array
    {
        try {
            $technicianIdBinary = UuidBinary::toBinary($technicianUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Technician not found.');
        }

        return DB::transaction(function () use ($request, $technicianUuid, $technicianIdBinary, $actor, $specializationId, $isPrimary, $isActive): array {
            $technician = DB::table('technicians')->where('id', $technicianIdBinary)->lockForUpdate()->first(['id']);

            if ($technician === null) {
                return $this->notFound('Technician not found.');
            }

            $specialization = DB::table('specializations')->where('id', $specializationId)->first(['id', 'name']);

            if ($specialization === null) {
                return $this->unprocessable('The given data was invalid.', ['specialization_id' => ['This specialization does not exist.']]);
            }

            $existing = DB::table('technician_specializations')
                ->where('technician_id', $technicianIdBinary)
                ->where('specialization_id', $specializationId)
                ->first();

            $now = now();

            try {
                DB::table('technician_specializations')->updateOrInsert(
                    ['technician_id' => $technicianIdBinary, 'specialization_id' => $specializationId],
                    [
                        'is_primary' => $isPrimary ? 1 : 0,
                        'is_active' => $isActive ? 1 : 0,
                        'updated_at' => $now,
                        'created_at' => $existing?->created_at ?? $now,
                    ]
                );
            } catch (UniqueConstraintViolationException) {
                return $this->conflict('This technician already has an active primary specialization - deactivate or demote it first.');
            }

            AdminAuditLogger::record(
                $request,
                $actor,
                'TECHNICIAN_SPECIALIZATION_CHANGED',
                'TECHNICIAN',
                $technicianUuid,
                ['specialization_id' => $specializationId, 'specialization_name' => $specialization->name, 'is_primary' => $isPrimary, 'is_active' => $isActive],
                $existing === null ? null : ['is_primary' => (bool) $existing->is_primary, 'is_active' => (bool) $existing->is_active],
            );

            $updated = AdminTechnicianPresenter::loadForDetail($technicianIdBinary);

            return $this->ok(200, 'Technician specialization saved successfully.', ['technician' => AdminTechnicianPresenter::detail($updated)]);
        });
    }
}
