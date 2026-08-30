<?php

namespace App\Actions\Admin\Service;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminServicePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BLUE V1 Phase B23-ext - create/update/activate/deactivate for one
 * `service_checkpoint_groups` row (e.g. "Engine & Lubrication"). Never a
 * hard delete - deactivating a group leaves its historical structure
 * intact and simply hides it (and, by extension, its checkpoints - see
 * App\Support\Admin\AdminServicePresenter::checkpointGroupsFor()) from
 * customer-facing counts/lists. `checkpoint_count`/`active_checkpoint_
 * count` are always DERIVED, never stored - see
 * AdminServicePresenter::checkpointGroupsFor()'s docblock.
 */
final class AdminServiceCheckpointGroupAction
{
    use BuildsCartResult;

    /**
     * @param  array{name: string, description: ?string, display_order: int}  $data
     */
    public function create(Request $request, User $actor, string $serviceUuid, array $data): array
    {
        try {
            $serviceIdBinary = UuidBinary::toBinary($serviceUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service not found.');
        }

        return DB::transaction(function () use ($request, $serviceUuid, $serviceIdBinary, $actor, $data): array {
            $service = DB::table('services')->where('id', $serviceIdBinary)->lockForUpdate()->first(['id']);

            if ($service === null) {
                return $this->notFound('Service not found.');
            }

            if (DB::table('service_checkpoint_groups')->where('service_id', $serviceIdBinary)->where('name', $data['name'])->exists()) {
                return $this->conflict('A checkpoint group with this name already exists on this service.');
            }

            $groupUuid = UuidBinary::generate();
            $now = now();

            DB::table('service_checkpoint_groups')->insert([
                'id' => UuidBinary::toBinary($groupUuid),
                'service_id' => $serviceIdBinary,
                'name' => $data['name'],
                'description' => $data['description'],
                'display_order' => $data['display_order'],
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_CHECKPOINT_GROUP_CREATED',
                'SERVICE',
                $serviceUuid,
                ['group_uuid' => $groupUuid, 'name' => $data['name']],
            );

            $updated = AdminServicePresenter::loadForDetail($serviceIdBinary);

            return $this->ok(201, 'Checkpoint group created successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }

    /**
     * @param  array{name: string, description: ?string, display_order: int}  $data
     */
    public function update(Request $request, User $actor, string $groupUuid, array $data): array
    {
        try {
            $groupIdBinary = UuidBinary::toBinary($groupUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Checkpoint group not found.');
        }

        return DB::transaction(function () use ($request, $groupUuid, $groupIdBinary, $actor, $data): array {
            $group = DB::table('service_checkpoint_groups')->where('id', $groupIdBinary)->lockForUpdate()->first(['id', 'service_id', 'name', 'display_order']);

            if ($group === null) {
                return $this->notFound('Checkpoint group not found.');
            }

            if (DB::table('service_checkpoint_groups')
                ->where('service_id', $group->service_id)
                ->where('name', $data['name'])
                ->where('id', '!=', $groupIdBinary)
                ->exists()) {
                return $this->conflict('A checkpoint group with this name already exists on this service.');
            }

            DB::table('service_checkpoint_groups')->where('id', $groupIdBinary)->update([
                'name' => $data['name'],
                'description' => $data['description'],
                'display_order' => $data['display_order'],
                'updated_at' => now(),
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_CHECKPOINT_GROUP_UPDATED',
                'SERVICE',
                UuidBinary::toString($group->service_id),
                ['group_uuid' => $groupUuid, 'name' => $data['name'], 'display_order' => $data['display_order']],
                ['name' => $group->name, 'display_order' => (int) $group->display_order],
            );

            $updated = AdminServicePresenter::loadForDetail($group->service_id);

            return $this->ok(200, 'Checkpoint group updated successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }

    public function setActive(Request $request, User $actor, string $groupUuid, bool $isActive): array
    {
        try {
            $groupIdBinary = UuidBinary::toBinary($groupUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Checkpoint group not found.');
        }

        return DB::transaction(function () use ($request, $groupUuid, $groupIdBinary, $actor, $isActive): array {
            $group = DB::table('service_checkpoint_groups')->where('id', $groupIdBinary)->lockForUpdate()->first(['id', 'service_id', 'is_active']);

            if ($group === null) {
                return $this->notFound('Checkpoint group not found.');
            }

            if ((bool) $group->is_active === $isActive) {
                $updated = AdminServicePresenter::loadForDetail($group->service_id);

                return $this->ok(200, $isActive ? 'Checkpoint group is already active.' : 'Checkpoint group is already inactive.', ['service' => AdminServicePresenter::detail($updated)]);
            }

            DB::table('service_checkpoint_groups')->where('id', $groupIdBinary)->update(['is_active' => $isActive ? 1 : 0, 'updated_at' => now()]);

            AdminAuditLogger::record(
                $request,
                $actor,
                $isActive ? 'SERVICE_CHECKPOINT_GROUP_ACTIVATED' : 'SERVICE_CHECKPOINT_GROUP_DEACTIVATED',
                'SERVICE',
                UuidBinary::toString($group->service_id),
                ['group_uuid' => $groupUuid],
            );

            $updated = AdminServicePresenter::loadForDetail($group->service_id);

            return $this->ok(200, $isActive ? 'Checkpoint group activated successfully.' : 'Checkpoint group deactivated successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }
}
