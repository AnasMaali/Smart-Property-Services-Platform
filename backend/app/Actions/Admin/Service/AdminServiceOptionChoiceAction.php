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
 * BLUE V1 Phase B23 - create/update/activate/deactivate for one
 * `service_option_choices` row (e.g. "Small"/"Medium"/"Large" under an
 * Air Conditioner Size option). Only ever created under a
 * SINGLE_SELECT/MULTI_SELECT option - App\Actions\Admin\Service\
 * AdminServiceOptionAction is what enforces that shape on the parent
 * option itself. Deactivating a Choice never touches
 * `cart_item_option_choice_selections`/`booking_item_option_choice_selections`
 * - the same in-progress-Cart/historical-snapshot boundary
 * AdminServiceOptionAction's docblock already documents for Options.
 */
final class AdminServiceOptionChoiceAction
{
    use BuildsCartResult;

    /**
     * @param  array{code: string, name: string, description: ?string, display_order: int}  $data
     */
    public function create(Request $request, User $actor, string $optionUuid, array $data): array
    {
        try {
            $optionIdBinary = UuidBinary::toBinary($optionUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service option not found.');
        }

        return DB::transaction(function () use ($request, $optionUuid, $optionIdBinary, $actor, $data): array {
            $option = DB::table('service_options')
                ->join('service_option_types', 'service_option_types.id', '=', 'service_options.option_type_id')
                ->where('service_options.id', $optionIdBinary)
                ->lockForUpdate()
                ->first(['service_options.id', 'service_options.service_id', 'service_option_types.code as type_code']);

            if ($option === null) {
                return $this->notFound('Service option not found.');
            }

            if (! in_array($option->type_code, ['SINGLE_SELECT', 'MULTI_SELECT'], true)) {
                return $this->unprocessable('Choices can only be added to a SINGLE_SELECT/MULTI_SELECT option.');
            }

            if (DB::table('service_option_choices')->where('service_option_id', $optionIdBinary)->where('code', $data['code'])->exists()) {
                return $this->conflict('A choice with this code already exists on this option.');
            }

            if (DB::table('service_option_choices')->where('service_option_id', $optionIdBinary)->where('name', $data['name'])->exists()) {
                return $this->conflict('A choice with this name already exists on this option.');
            }

            $choiceUuid = UuidBinary::generate();
            $now = now();

            DB::table('service_option_choices')->insert([
                'id' => UuidBinary::toBinary($choiceUuid),
                'service_option_id' => $optionIdBinary,
                'code' => $data['code'],
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
                'SERVICE_OPTION_CHOICE_CREATED',
                'SERVICE',
                UuidBinary::toString($option->service_id),
                ['option_uuid' => $optionUuid, 'choice_uuid' => $choiceUuid, 'code' => $data['code']],
            );

            $updated = AdminServicePresenter::loadForDetail($option->service_id);

            return $this->ok(201, 'Choice created successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }

    /**
     * @param  array{name: string, description: ?string, display_order: int}  $data
     */
    public function update(Request $request, User $actor, string $choiceUuid, array $data): array
    {
        try {
            $choiceIdBinary = UuidBinary::toBinary($choiceUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Choice not found.');
        }

        return DB::transaction(function () use ($request, $choiceUuid, $choiceIdBinary, $actor, $data): array {
            $choice = DB::table('service_option_choices')
                ->join('service_options', 'service_options.id', '=', 'service_option_choices.service_option_id')
                ->where('service_option_choices.id', $choiceIdBinary)
                ->lockForUpdate()
                ->first(['service_option_choices.id', 'service_option_choices.service_option_id', 'service_options.service_id', 'service_option_choices.name', 'service_option_choices.display_order']);

            if ($choice === null) {
                return $this->notFound('Choice not found.');
            }

            if (DB::table('service_option_choices')
                ->where('service_option_id', $choice->service_option_id)
                ->where('name', $data['name'])
                ->where('id', '!=', $choiceIdBinary)
                ->exists()) {
                return $this->conflict('A choice with this name already exists on this option.');
            }

            DB::table('service_option_choices')->where('id', $choiceIdBinary)->update([
                'name' => $data['name'],
                'description' => $data['description'],
                'display_order' => $data['display_order'],
                'updated_at' => now(),
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_OPTION_CHOICE_UPDATED',
                'SERVICE',
                UuidBinary::toString($choice->service_id),
                ['choice_uuid' => $choiceUuid, 'name' => $data['name'], 'display_order' => $data['display_order']],
                ['name' => $choice->name, 'display_order' => (int) $choice->display_order],
            );

            $updated = AdminServicePresenter::loadForDetail($choice->service_id);

            return $this->ok(200, 'Choice updated successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }

    public function setActive(Request $request, User $actor, string $choiceUuid, bool $isActive): array
    {
        try {
            $choiceIdBinary = UuidBinary::toBinary($choiceUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Choice not found.');
        }

        return DB::transaction(function () use ($request, $choiceUuid, $choiceIdBinary, $actor, $isActive): array {
            $choice = DB::table('service_option_choices')
                ->join('service_options', 'service_options.id', '=', 'service_option_choices.service_option_id')
                ->where('service_option_choices.id', $choiceIdBinary)
                ->lockForUpdate()
                ->first(['service_option_choices.id', 'service_options.service_id', 'service_option_choices.is_active']);

            if ($choice === null) {
                return $this->notFound('Choice not found.');
            }

            if ((bool) $choice->is_active === $isActive) {
                $updated = AdminServicePresenter::loadForDetail($choice->service_id);

                return $this->ok(200, $isActive ? 'Choice is already active.' : 'Choice is already inactive.', ['service' => AdminServicePresenter::detail($updated)]);
            }

            DB::table('service_option_choices')->where('id', $choiceIdBinary)->update(['is_active' => $isActive ? 1 : 0, 'updated_at' => now()]);

            AdminAuditLogger::record(
                $request,
                $actor,
                $isActive ? 'SERVICE_OPTION_CHOICE_ACTIVATED' : 'SERVICE_OPTION_CHOICE_DEACTIVATED',
                'SERVICE',
                UuidBinary::toString($choice->service_id),
                ['choice_uuid' => $choiceUuid],
            );

            $updated = AdminServicePresenter::loadForDetail($choice->service_id);

            return $this->ok(200, $isActive ? 'Choice activated successfully.' : 'Choice deactivated successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }
}
