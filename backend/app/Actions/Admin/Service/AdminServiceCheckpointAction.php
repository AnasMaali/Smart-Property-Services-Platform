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
 * `service_checkpoints` row (e.g. "Replace engine oil" under "Engine &
 * Lubrication"). `action_type_id` is always resolved from the seeded
 * `service_checkpoint_action_types` lookup (REPLACE/INSPECT/
 * INSPECT_AND_CLEAN/TOP_UP/INSPECT_AND_ADJUST/UPDATE) - never a
 * client-supplied arbitrary code, matching the same "extensible lookup,
 * never free-form" convention `service_option_types.code` already uses.
 * `update()` may move a checkpoint to a different group of the SAME
 * Service ("move between groups where safe") - moving it to a group
 * belonging to a different Service is rejected.
 */
final class AdminServiceCheckpointAction
{
    use BuildsCartResult;

    /**
     * @param  array{name: string, description: ?string, action_type_code: string, display_order: int}  $data
     */
    public function create(Request $request, User $actor, string $groupUuid, array $data): array
    {
        try {
            $groupIdBinary = UuidBinary::toBinary($groupUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Checkpoint group not found.');
        }

        return DB::transaction(function () use ($request, $groupUuid, $groupIdBinary, $actor, $data): array {
            $group = DB::table('service_checkpoint_groups')->where('id', $groupIdBinary)->lockForUpdate()->first(['id', 'service_id']);

            if ($group === null) {
                return $this->notFound('Checkpoint group not found.');
            }

            $actionType = DB::table('service_checkpoint_action_types')->where('code', $data['action_type_code'])->first(['id']);

            if ($actionType === null) {
                return $this->unprocessable('The given data was invalid.', ['action_type_code' => ['This checkpoint action type does not exist.']]);
            }

            if (DB::table('service_checkpoints')->where('group_id', $groupIdBinary)->where('name', $data['name'])->exists()) {
                return $this->conflict('A checkpoint with this name already exists in this group.');
            }

            $checkpointUuid = UuidBinary::generate();
            $now = now();

            DB::table('service_checkpoints')->insert([
                'id' => UuidBinary::toBinary($checkpointUuid),
                'group_id' => $groupIdBinary,
                'name' => $data['name'],
                'description' => $data['description'],
                'action_type_id' => $actionType->id,
                'display_order' => $data['display_order'],
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_CHECKPOINT_CREATED',
                'SERVICE',
                UuidBinary::toString($group->service_id),
                ['group_uuid' => $groupUuid, 'checkpoint_uuid' => $checkpointUuid, 'name' => $data['name'], 'action_type_code' => $data['action_type_code']],
            );

            $updated = AdminServicePresenter::loadForDetail($group->service_id);

            return $this->ok(201, 'Checkpoint created successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }

    /**
     * @param  array{name: string, description: ?string, action_type_code: string, display_order: int, group_uuid: ?string}  $data
     */
    public function update(Request $request, User $actor, string $checkpointUuid, array $data): array
    {
        try {
            $checkpointIdBinary = UuidBinary::toBinary($checkpointUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Checkpoint not found.');
        }

        return DB::transaction(function () use ($request, $checkpointUuid, $checkpointIdBinary, $actor, $data): array {
            $checkpoint = DB::table('service_checkpoints')
                ->join('service_checkpoint_groups', 'service_checkpoint_groups.id', '=', 'service_checkpoints.group_id')
                ->where('service_checkpoints.id', $checkpointIdBinary)
                ->lockForUpdate()
                ->first(['service_checkpoints.id', 'service_checkpoints.group_id', 'service_checkpoints.name', 'service_checkpoints.display_order', 'service_checkpoint_groups.service_id']);

            if ($checkpoint === null) {
                return $this->notFound('Checkpoint not found.');
            }

            $actionType = DB::table('service_checkpoint_action_types')->where('code', $data['action_type_code'])->first(['id']);

            if ($actionType === null) {
                return $this->unprocessable('The given data was invalid.', ['action_type_code' => ['This checkpoint action type does not exist.']]);
            }

            $targetGroupIdBinary = $checkpoint->group_id;

            if (! empty($data['group_uuid'])) {
                try {
                    $targetGroupIdBinary = UuidBinary::toBinary($data['group_uuid']);
                } catch (InvalidArgumentException) {
                    return $this->unprocessable('The given data was invalid.', ['group_uuid' => ['This is not a valid checkpoint group.']]);
                }

                $targetGroup = DB::table('service_checkpoint_groups')->where('id', $targetGroupIdBinary)->first(['id', 'service_id']);

                if ($targetGroup === null || bin2hex($targetGroup->service_id) !== bin2hex($checkpoint->service_id)) {
                    return $this->unprocessable('The given data was invalid.', ['group_uuid' => ['This checkpoint group does not belong to the same service.']]);
                }
            }

            if (DB::table('service_checkpoints')
                ->where('group_id', $targetGroupIdBinary)
                ->where('name', $data['name'])
                ->where('id', '!=', $checkpointIdBinary)
                ->exists()) {
                return $this->conflict('A checkpoint with this name already exists in the target group.');
            }

            DB::table('service_checkpoints')->where('id', $checkpointIdBinary)->update([
                'group_id' => $targetGroupIdBinary,
                'name' => $data['name'],
                'description' => $data['description'],
                'action_type_id' => $actionType->id,
                'display_order' => $data['display_order'],
                'updated_at' => now(),
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_CHECKPOINT_UPDATED',
                'SERVICE',
                UuidBinary::toString($checkpoint->service_id),
                ['checkpoint_uuid' => $checkpointUuid, 'name' => $data['name'], 'action_type_code' => $data['action_type_code'], 'display_order' => $data['display_order']],
                ['name' => $checkpoint->name, 'display_order' => (int) $checkpoint->display_order],
            );

            $updated = AdminServicePresenter::loadForDetail($checkpoint->service_id);

            return $this->ok(200, 'Checkpoint updated successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }

    public function setActive(Request $request, User $actor, string $checkpointUuid, bool $isActive): array
    {
        try {
            $checkpointIdBinary = UuidBinary::toBinary($checkpointUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Checkpoint not found.');
        }

        return DB::transaction(function () use ($request, $checkpointUuid, $checkpointIdBinary, $actor, $isActive): array {
            $checkpoint = DB::table('service_checkpoints')
                ->join('service_checkpoint_groups', 'service_checkpoint_groups.id', '=', 'service_checkpoints.group_id')
                ->where('service_checkpoints.id', $checkpointIdBinary)
                ->lockForUpdate()
                ->first(['service_checkpoints.id', 'service_checkpoints.is_active', 'service_checkpoint_groups.service_id']);

            if ($checkpoint === null) {
                return $this->notFound('Checkpoint not found.');
            }

            if ((bool) $checkpoint->is_active === $isActive) {
                $updated = AdminServicePresenter::loadForDetail($checkpoint->service_id);

                return $this->ok(200, $isActive ? 'Checkpoint is already active.' : 'Checkpoint is already inactive.', ['service' => AdminServicePresenter::detail($updated)]);
            }

            DB::table('service_checkpoints')->where('id', $checkpointIdBinary)->update(['is_active' => $isActive ? 1 : 0, 'updated_at' => now()]);

            AdminAuditLogger::record(
                $request,
                $actor,
                $isActive ? 'SERVICE_CHECKPOINT_ACTIVATED' : 'SERVICE_CHECKPOINT_DEACTIVATED',
                'SERVICE',
                UuidBinary::toString($checkpoint->service_id),
                ['checkpoint_uuid' => $checkpointUuid],
            );

            $updated = AdminServicePresenter::loadForDetail($checkpoint->service_id);

            return $this->ok(200, $isActive ? 'Checkpoint activated successfully.' : 'Checkpoint deactivated successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }
}
