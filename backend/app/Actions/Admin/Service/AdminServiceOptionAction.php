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
 * `service_options` row (+ its type-specific `service_option_numeric_rules`
 * / `service_option_selection_rules` child row). `option_type_id` is
 * immutable after creation - the customer-facing selection UI, App\Support\
 * Cart\CartSelectionValidator, and the pricing engine's OPTION_NUMERIC_VALUE/
 * OPTION_CHOICE/OPTION_BOOLEAN_VALUE condition subjects all key off the
 * option's type, so changing it after choices/pricing-rule conditions
 * already reference it would silently invalidate them - a Service needing a
 * different option shape gets a new Option, never a retyped one.
 *
 * Deactivating an Option never touches `cart_item_option_selections` (an
 * in-progress Cart keeps whatever it already selected - `ON DELETE
 * RESTRICT` on that FK makes this the schema's own policy already) or any
 * `booking_item_option_selections` snapshot; it only prevents the option
 * from being selected on a FUTURE Cart addition (App\Support\Cart\
 * CartSelectionValidator already filters to `is_active = 1`).
 */
final class AdminServiceOptionAction
{
    use BuildsCartResult;

    private const NUMERIC_TYPE = 'NUMBER';

    private const SELECT_TYPES = ['SINGLE_SELECT', 'MULTI_SELECT'];

    /**
     * @param  array<string, mixed>  $data
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

            $optionType = DB::table('service_option_types')->where('code', $data['option_type_code'])->first(['id', 'code']);

            if ($optionType === null) {
                return $this->unprocessable('The given data was invalid.', ['option_type_code' => ['This option type does not exist.']]);
            }

            $errors = $this->validateTypeShape($optionType->code, $data);

            if ($errors !== []) {
                return $this->unprocessable('This option cannot be saved.', $errors);
            }

            if (DB::table('service_options')->where('service_id', $serviceIdBinary)->where('code', $data['code'])->exists()) {
                return $this->conflict('An option with this code already exists on this service.');
            }

            $optionUuid = UuidBinary::generate();
            $optionIdBinary = UuidBinary::toBinary($optionUuid);
            $now = now();

            DB::table('service_options')->insert([
                'id' => $optionIdBinary,
                'service_id' => $serviceIdBinary,
                'option_type_id' => $optionType->id,
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'],
                'is_required' => $data['is_required'] ? 1 : 0,
                'display_order' => $data['display_order'],
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->saveTypeRules($optionIdBinary, $optionType->code, $data, $now);

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_OPTION_CREATED',
                'SERVICE',
                $serviceUuid,
                ['option_uuid' => $optionUuid, 'code' => $data['code'], 'type' => $optionType->code],
            );

            $updated = AdminServicePresenter::loadForDetail($serviceIdBinary);

            return $this->ok(201, 'Service option created successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Request $request, User $actor, string $optionUuid, array $data): array
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
                ->first(['service_options.id', 'service_options.service_id', 'service_options.name', 'service_options.display_order', 'service_option_types.code as type_code']);

            if ($option === null) {
                return $this->notFound('Service option not found.');
            }

            $errors = $this->validateTypeShape($option->type_code, $data);

            if ($errors !== []) {
                return $this->unprocessable('This option cannot be saved.', $errors);
            }

            DB::table('service_options')->where('id', $optionIdBinary)->update([
                'name' => $data['name'],
                'description' => $data['description'],
                'is_required' => $data['is_required'] ? 1 : 0,
                'display_order' => $data['display_order'],
                'updated_at' => now(),
            ]);

            $this->saveTypeRules($optionIdBinary, $option->type_code, $data, now());

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_OPTION_UPDATED',
                'SERVICE',
                UuidBinary::toString($option->service_id),
                ['option_uuid' => $optionUuid, 'name' => $data['name'], 'display_order' => $data['display_order']],
                ['name' => $option->name, 'display_order' => (int) $option->display_order],
            );

            $updated = AdminServicePresenter::loadForDetail($option->service_id);

            return $this->ok(200, 'Service option updated successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }

    public function setActive(Request $request, User $actor, string $optionUuid, bool $isActive): array
    {
        try {
            $optionIdBinary = UuidBinary::toBinary($optionUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service option not found.');
        }

        return DB::transaction(function () use ($request, $optionUuid, $optionIdBinary, $actor, $isActive): array {
            $option = DB::table('service_options')->where('id', $optionIdBinary)->lockForUpdate()->first(['id', 'service_id', 'is_active']);

            if ($option === null) {
                return $this->notFound('Service option not found.');
            }

            if ((bool) $option->is_active === $isActive) {
                $updated = AdminServicePresenter::loadForDetail($option->service_id);

                return $this->ok(200, $isActive ? 'Service option is already active.' : 'Service option is already inactive.', ['service' => AdminServicePresenter::detail($updated)]);
            }

            DB::table('service_options')->where('id', $optionIdBinary)->update(['is_active' => $isActive ? 1 : 0, 'updated_at' => now()]);

            AdminAuditLogger::record(
                $request,
                $actor,
                $isActive ? 'SERVICE_OPTION_ACTIVATED' : 'SERVICE_OPTION_DEACTIVATED',
                'SERVICE',
                UuidBinary::toString($option->service_id),
                ['option_uuid' => $optionUuid],
            );

            $updated = AdminServicePresenter::loadForDetail($option->service_id);

            return $this->ok(200, $isActive ? 'Service option activated successfully.' : 'Service option deactivated successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function validateTypeShape(string $typeCode, array $data): array
    {
        $errors = [];
        $hasNumericRule = isset($data['numeric_rule']);
        $hasSelectionRule = isset($data['selection_rule']);

        if ($typeCode === self::NUMERIC_TYPE && ! $hasNumericRule) {
            $errors[] = 'A NUMBER option requires numeric_rule.';
        }

        if ($typeCode !== self::NUMERIC_TYPE && $hasNumericRule) {
            $errors[] = 'numeric_rule is only valid for a NUMBER option.';
        }

        if (in_array($typeCode, self::SELECT_TYPES, true) && ! $hasSelectionRule) {
            $errors[] = "A {$typeCode} option requires selection_rule.";
        }

        if (! in_array($typeCode, self::SELECT_TYPES, true) && $hasSelectionRule) {
            $errors[] = 'selection_rule is only valid for a SINGLE_SELECT/MULTI_SELECT option.';
        }

        if ($typeCode === 'SINGLE_SELECT' && $hasSelectionRule && (int) ($data['selection_rule']['maximum_selections'] ?? 1) > 1) {
            $errors[] = 'SINGLE_SELECT cannot allow more than one selection.';
        }

        if ($hasSelectionRule
            && isset($data['selection_rule']['minimum_selections'], $data['selection_rule']['maximum_selections'])
            && (int) $data['selection_rule']['minimum_selections'] > (int) $data['selection_rule']['maximum_selections']) {
            $errors[] = 'selection_rule.minimum_selections cannot exceed maximum_selections.';
        }

        if ($hasNumericRule && isset($data['numeric_rule']['measurement_unit_code'])
            && ! DB::table('measurement_units')->where('code', $data['numeric_rule']['measurement_unit_code'])->exists()) {
            $errors[] = 'numeric_rule.measurement_unit_code does not reference an existing measurement unit.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveTypeRules(string $optionIdBinary, string $typeCode, array $data, $now): void
    {
        if ($typeCode === self::NUMERIC_TYPE) {
            $rule = $data['numeric_rule'];
            $measurementUnitId = isset($rule['measurement_unit_code'])
                ? DB::table('measurement_units')->where('code', $rule['measurement_unit_code'])->value('id')
                : null;

            DB::table('service_option_numeric_rules')->updateOrInsert(
                ['service_option_id' => $optionIdBinary],
                [
                    'measurement_unit_id' => $measurementUnitId,
                    'minimum_value' => $rule['min_value'],
                    'maximum_value' => $rule['max_value'],
                    'step_value' => $rule['step_value'] ?? '1.000000',
                    'default_value' => $rule['default_value'] ?? null,
                    'decimal_places' => $rule['decimal_places'] ?? 0,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        if (in_array($typeCode, self::SELECT_TYPES, true)) {
            $rule = $data['selection_rule'];

            DB::table('service_option_selection_rules')->updateOrInsert(
                ['service_option_id' => $optionIdBinary],
                [
                    'minimum_selections' => $rule['minimum_selections'] ?? 0,
                    'maximum_selections' => $rule['maximum_selections'] ?? ($typeCode === 'SINGLE_SELECT' ? 1 : 1),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}
