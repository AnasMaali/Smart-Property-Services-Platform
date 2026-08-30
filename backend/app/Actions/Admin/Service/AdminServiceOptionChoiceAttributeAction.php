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
 * `service_option_choice_attributes` row: generic typed metadata on a
 * SINGLE_SELECT/MULTI_SELECT choice (e.g. a car-service package choice's
 * oil brand/grade, its own duration, recommended odometer). The attribute
 * VOCABULARY (`service_option_choice_attribute_types`) is seed-only,
 * extensible only by a future migration - never by a client-supplied code
 * at request time (see App\Actions\Admin\Service\
 * AdminListServiceOptionChoiceAttributeTypesAction's docblock and the
 * migration's own docblock for why).
 *
 * `data_type` on the referenced type governs which of `value_string`/
 * `value_number` this Action writes - a cross-table invariant MySQL cannot
 * express as a single-table CHECK (the schema itself only enforces
 * "exactly one of the two columns is set"), so it is validated here, in
 * PHP, exactly like AdminSetServiceOriginalPriceAction validates
 * `original >= current` against a different table.
 */
final class AdminServiceOptionChoiceAttributeAction
{
    use BuildsCartResult;

    /**
     * @param  array{attribute_type_code: string, value: string}  $data
     */
    public function create(Request $request, User $actor, string $choiceUuid, array $data): array
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
                ->first(['service_option_choices.id', 'service_options.service_id']);

            if ($choice === null) {
                return $this->notFound('Choice not found.');
            }

            $attributeType = DB::table('service_option_choice_attribute_types')->where('code', $data['attribute_type_code'])->first(['id', 'data_type']);

            if ($attributeType === null) {
                return $this->unprocessable('The given data was invalid.', ['attribute_type_code' => ['This attribute type does not exist.']]);
            }

            if (DB::table('service_option_choice_attributes')->where('choice_id', $choiceIdBinary)->where('attribute_type_id', $attributeType->id)->exists()) {
                return $this->conflict('This choice already has a value for this attribute - edit it instead.');
            }

            [$valueString, $valueNumber, $error] = $this->splitValue($attributeType->data_type, $data['value']);

            if ($error !== null) {
                return $this->unprocessable('The given data was invalid.', ['value' => [$error]]);
            }

            $attributeUuid = UuidBinary::generate();
            $now = now();

            DB::table('service_option_choice_attributes')->insert([
                'id' => UuidBinary::toBinary($attributeUuid),
                'choice_id' => $choiceIdBinary,
                'attribute_type_id' => $attributeType->id,
                'value_string' => $valueString,
                'value_number' => $valueNumber,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_OPTION_CHOICE_ATTRIBUTE_CREATED',
                'SERVICE',
                UuidBinary::toString($choice->service_id),
                ['choice_uuid' => $choiceUuid, 'attribute_uuid' => $attributeUuid, 'attribute_type_code' => $data['attribute_type_code'], 'value' => $data['value']],
            );

            $updated = AdminServicePresenter::loadForDetail($choice->service_id);

            return $this->ok(201, 'Attribute added successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }

    /**
     * @param  array{value: string}  $data
     */
    public function update(Request $request, User $actor, string $attributeUuid, array $data): array
    {
        try {
            $attributeIdBinary = UuidBinary::toBinary($attributeUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Attribute not found.');
        }

        return DB::transaction(function () use ($request, $attributeUuid, $attributeIdBinary, $actor, $data): array {
            $attribute = DB::table('service_option_choice_attributes')
                ->join('service_option_choice_attribute_types', 'service_option_choice_attribute_types.id', '=', 'service_option_choice_attributes.attribute_type_id')
                ->join('service_option_choices', 'service_option_choices.id', '=', 'service_option_choice_attributes.choice_id')
                ->join('service_options', 'service_options.id', '=', 'service_option_choices.service_option_id')
                ->where('service_option_choice_attributes.id', $attributeIdBinary)
                ->lockForUpdate()
                ->first([
                    'service_option_choice_attributes.id',
                    'service_option_choice_attributes.value_string',
                    'service_option_choice_attributes.value_number',
                    'service_option_choice_attribute_types.data_type',
                    'service_options.service_id',
                ]);

            if ($attribute === null) {
                return $this->notFound('Attribute not found.');
            }

            [$valueString, $valueNumber, $error] = $this->splitValue($attribute->data_type, $data['value']);

            if ($error !== null) {
                return $this->unprocessable('The given data was invalid.', ['value' => [$error]]);
            }

            DB::table('service_option_choice_attributes')->where('id', $attributeIdBinary)->update([
                'value_string' => $valueString,
                'value_number' => $valueNumber,
                'updated_at' => now(),
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_OPTION_CHOICE_ATTRIBUTE_UPDATED',
                'SERVICE',
                UuidBinary::toString($attribute->service_id),
                ['attribute_uuid' => $attributeUuid, 'value' => $data['value']],
                ['value' => $attribute->data_type === 'NUMBER' ? $attribute->value_number : $attribute->value_string],
            );

            $updated = AdminServicePresenter::loadForDetail($attribute->service_id);

            return $this->ok(200, 'Attribute updated successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }

    public function setActive(Request $request, User $actor, string $attributeUuid, bool $isActive): array
    {
        try {
            $attributeIdBinary = UuidBinary::toBinary($attributeUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Attribute not found.');
        }

        return DB::transaction(function () use ($request, $attributeUuid, $attributeIdBinary, $actor, $isActive): array {
            $attribute = DB::table('service_option_choice_attributes')
                ->join('service_option_choices', 'service_option_choices.id', '=', 'service_option_choice_attributes.choice_id')
                ->join('service_options', 'service_options.id', '=', 'service_option_choices.service_option_id')
                ->where('service_option_choice_attributes.id', $attributeIdBinary)
                ->lockForUpdate()
                ->first(['service_option_choice_attributes.id', 'service_option_choice_attributes.is_active', 'service_options.service_id']);

            if ($attribute === null) {
                return $this->notFound('Attribute not found.');
            }

            if ((bool) $attribute->is_active === $isActive) {
                $updated = AdminServicePresenter::loadForDetail($attribute->service_id);

                return $this->ok(200, $isActive ? 'Attribute is already active.' : 'Attribute is already inactive.', ['service' => AdminServicePresenter::detail($updated)]);
            }

            DB::table('service_option_choice_attributes')->where('id', $attributeIdBinary)->update(['is_active' => $isActive ? 1 : 0, 'updated_at' => now()]);

            AdminAuditLogger::record(
                $request,
                $actor,
                $isActive ? 'SERVICE_OPTION_CHOICE_ATTRIBUTE_ACTIVATED' : 'SERVICE_OPTION_CHOICE_ATTRIBUTE_DEACTIVATED',
                'SERVICE',
                UuidBinary::toString($attribute->service_id),
                ['attribute_uuid' => $attributeUuid],
            );

            $updated = AdminServicePresenter::loadForDetail($attribute->service_id);

            return $this->ok(200, $isActive ? 'Attribute activated successfully.' : 'Attribute deactivated successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [value_string, value_number, error]
     */
    private function splitValue(string $dataType, string $value): array
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return [null, null, 'A value is required.'];
        }

        if ($dataType === 'NUMBER') {
            if (! is_numeric($trimmed)) {
                return [null, null, 'This attribute requires a numeric value.'];
            }

            return [null, $trimmed, null];
        }

        return [$trimmed, null, null];
    }
}
