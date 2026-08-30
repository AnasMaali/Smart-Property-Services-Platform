<?php

namespace App\Actions\Admin\Service;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminServicePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BLUE V1 Phase B23 - creates or updates one `service_specializations`
 * mapping row (upsert on the existing (service_id, specialization_id)
 * primary key) - the smallest correct operation for a many-to-many
 * relation, never simplified into a single-specialization field (BLUE V1
 * catalog spec section 10 explicitly requires preserving the many-to-many
 * model this schema already has). "Removing" a specialization requirement
 * is calling this again with `is_active: false` - never a hard delete
 * (`fk_service_specializations_service`/`_specialization` are ON DELETE
 * RESTRICT, and BLUE V1 standing policy is deactivate over delete anyway).
 *
 * This changes ONLY what a FUTURE Admin assignment attempt will accept
 * (App\Actions\Technician\AssignTechnicianToBookingItemAction::
 * requiredSpecializationIds() reads this table live, at assignment time) -
 * every already-committed `technician_assignments` row snapshots its own
 * `specialization_id` at assignment time and is never touched here.
 *
 * `uq_service_specializations_primary` (a generated-column unique index on
 * (service_id, primary_marker)) already enforces "at most one active
 * primary specialization per service" at the database level - a second
 * `is_primary: true` row is rejected as a plain 409, never a raw
 * constraint-violation error.
 */
final class AdminSetServiceSpecializationAction
{
    use BuildsCartResult;

    public function handle(Request $request, string $serviceUuid, User $actor, int $specializationId, bool $isPrimary, bool $isActive, int $displayOrder): array
    {
        try {
            $serviceIdBinary = UuidBinary::toBinary($serviceUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service not found.');
        }

        return DB::transaction(function () use ($request, $serviceUuid, $serviceIdBinary, $actor, $specializationId, $isPrimary, $isActive, $displayOrder): array {
            $service = DB::table('services')->where('id', $serviceIdBinary)->lockForUpdate()->first(['id']);

            if ($service === null) {
                return $this->notFound('Service not found.');
            }

            $specialization = DB::table('specializations')->where('id', $specializationId)->first(['id', 'name']);

            if ($specialization === null) {
                return $this->unprocessable('The given data was invalid.', ['specialization_id' => ['This specialization does not exist.']]);
            }

            $existing = DB::table('service_specializations')
                ->where('service_id', $serviceIdBinary)
                ->where('specialization_id', $specializationId)
                ->first();

            $now = now();

            try {
                DB::table('service_specializations')->updateOrInsert(
                    ['service_id' => $serviceIdBinary, 'specialization_id' => $specializationId],
                    [
                        'is_primary' => $isPrimary ? 1 : 0,
                        'is_active' => $isActive ? 1 : 0,
                        'display_order' => $displayOrder,
                        'updated_at' => $now,
                        'created_at' => $existing?->created_at ?? $now,
                    ]
                );
            } catch (UniqueConstraintViolationException) {
                return $this->conflict('This service already has an active primary specialization - deactivate or demote it first.');
            }

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_SPECIALIZATION_CHANGED',
                'SERVICE',
                $serviceUuid,
                ['specialization_id' => $specializationId, 'specialization_name' => $specialization->name, 'is_primary' => $isPrimary, 'is_active' => $isActive],
                $existing === null ? null : ['is_primary' => (bool) $existing->is_primary, 'is_active' => (bool) $existing->is_active],
            );

            $updated = AdminServicePresenter::loadForDetail($serviceIdBinary);

            return $this->ok(200, 'Service specialization saved successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }
}
