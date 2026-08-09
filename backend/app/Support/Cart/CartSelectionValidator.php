<?php

namespace App\Support\Cart;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * The one generic, schema-driven validator for cart-item option selections.
 * Every option type (TEXT, NUMBER, BOOLEAN, SINGLE_SELECT, MULTI_SELECT) is
 * validated the same way: load the actual service_options/service_option_
 * numeric_rules/service_option_selection_rules/service_option_choices rows
 * for the submitted service and check the submission against them - there
 * is never a per-service or per-capability branch here.
 *
 * Both Add and Update cart-item flows call validate() with the exact
 * request-shaped `options` array (option_uuid + one value field). The
 * normalized entries it returns are ready both for persistence
 * (CartItemSelections::persist/replace) and for building the PricingEngine
 * selections map (toPricingSelections()).
 */
final class CartSelectionValidator
{
    private const VALUE_FIELDS = ['numeric_value', 'boolean_value', 'text_value', 'choice_uuids'];

    /**
     * @param  array<int, array<string, mixed>>  $optionsInput
     * @return array{errors: array<int, string>, options: array<int, array<string, mixed>>}
     */
    public function validate(string $serviceIdUuid, array $optionsInput): array
    {
        $serviceIdBinary = UuidBinary::toBinary($serviceIdUuid);

        $schemaOptions = DB::table('service_options')
            ->join('service_option_types', 'service_option_types.id', '=', 'service_options.option_type_id')
            ->where('service_options.service_id', $serviceIdBinary)
            ->where('service_options.is_active', 1)
            ->select([
                'service_options.id',
                'service_options.is_required',
                'service_options.name',
                'service_option_types.code as type_code',
            ])
            ->get();

        $optionsByUuid = $schemaOptions->keyBy(fn ($option) => UuidBinary::toString($option->id));
        $optionIds = $schemaOptions->pluck('id')->all();

        $numericRulesByOptionUuid = DB::table('service_option_numeric_rules')
            ->whereIn('service_option_id', $optionIds)
            ->get(['service_option_id', 'minimum_value', 'maximum_value', 'step_value', 'decimal_places'])
            ->keyBy(fn ($rule) => UuidBinary::toString($rule->service_option_id));

        $selectionRulesByOptionUuid = DB::table('service_option_selection_rules')
            ->whereIn('service_option_id', $optionIds)
            ->get(['service_option_id', 'minimum_selections', 'maximum_selections'])
            ->keyBy(fn ($rule) => UuidBinary::toString($rule->service_option_id));

        $choicesByOptionUuid = DB::table('service_option_choices')
            ->whereIn('service_option_id', $optionIds)
            ->where('is_active', 1)
            ->get(['id', 'service_option_id'])
            ->groupBy(fn ($choice) => UuidBinary::toString($choice->service_option_id))
            ->map(fn ($choices) => $choices->map(fn ($choice) => UuidBinary::toString($choice->id))->all());

        $errors = [];
        $normalized = [];
        $seenOptionUuids = [];

        foreach ($optionsInput as $index => $entry) {
            if (! is_array($entry) || ! is_string($entry['option_uuid'] ?? null)) {
                $errors[] = "options.{$index}: option_uuid is required.";

                continue;
            }

            $optionUuid = $entry['option_uuid'];

            if (isset($seenOptionUuids[$optionUuid])) {
                $errors[] = "Option {$optionUuid} was submitted more than once.";

                continue;
            }

            $seenOptionUuids[$optionUuid] = true;

            $schemaOption = $optionsByUuid->get($optionUuid);

            if ($schemaOption === null) {
                $errors[] = "Option {$optionUuid} is not a valid, active option for this service.";

                continue;
            }

            $result = match ($schemaOption->type_code) {
                'TEXT' => $this->validateText($optionUuid, $entry),
                'NUMBER' => $this->validateNumber($optionUuid, $entry, $numericRulesByOptionUuid->get($optionUuid)),
                'BOOLEAN' => $this->validateBoolean($optionUuid, $entry),
                'SINGLE_SELECT', 'MULTI_SELECT' => $this->validateSelect(
                    $optionUuid,
                    $entry,
                    $selectionRulesByOptionUuid->get($optionUuid),
                    $choicesByOptionUuid->get($optionUuid, []),
                ),
                default => ['error' => "Option {$optionUuid} has an unsupported type."],
            };

            if (isset($result['error'])) {
                $errors[] = $result['error'];

                continue;
            }

            $normalized[] = [
                'option_uuid' => $optionUuid,
                'option_id_binary' => UuidBinary::toBinary($optionUuid),
                'type' => $schemaOption->type_code,
                'numeric_value' => $result['numeric_value'] ?? null,
                'boolean_value' => $result['boolean_value'] ?? null,
                'text_value' => $result['text_value'] ?? null,
                'choice_uuids' => $result['choice_uuids'] ?? [],
            ];
        }

        foreach ($schemaOptions as $schemaOption) {
            $optionUuid = UuidBinary::toString($schemaOption->id);

            if ($schemaOption->is_required && ! isset($seenOptionUuids[$optionUuid])) {
                $errors[] = "Option \"{$schemaOption->name}\" is required.";
            }
        }

        return ['errors' => $errors, 'options' => $normalized];
    }

    /**
     * @param  array<int, array<string, mixed>>  $normalizedOptions
     * @return array<string, array<string, mixed>>
     */
    public function toPricingSelections(array $normalizedOptions): array
    {
        $selections = [];

        foreach ($normalizedOptions as $option) {
            $selections[$option['option_uuid']] = [
                'type' => in_array($option['type'], ['SINGLE_SELECT', 'MULTI_SELECT'], true) ? 'CHOICE' : $option['type'],
                'numeric_value' => $option['numeric_value'],
                'boolean_value' => $option['boolean_value'],
                'text_value' => $option['text_value'],
                'choice_ids' => $option['choice_uuids'],
            ];
        }

        return $selections;
    }

    /**
     * @return array<string, string>
     */
    private function presentValueFields(array $entry): array
    {
        $present = [];

        foreach (self::VALUE_FIELDS as $field) {
            if (! array_key_exists($field, $entry) || $entry[$field] === null) {
                continue;
            }

            if ($field === 'choice_uuids' && ! is_array($entry[$field])) {
                continue;
            }

            $present[$field] = $field;
        }

        return $present;
    }

    /**
     * @return array{error: string}|array{text_value: string}
     */
    private function validateText(string $optionUuid, array $entry): array
    {
        $present = $this->presentValueFields($entry);

        if ($present !== ['text_value' => 'text_value']) {
            return ['error' => "Option {$optionUuid} requires exactly one text_value."];
        }

        $value = $entry['text_value'];

        if (! is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 1000) {
            return ['error' => "Option {$optionUuid} text_value must be between 1 and 1000 characters."];
        }

        return ['text_value' => trim($value)];
    }

    /**
     * @return array{error: string}|array{numeric_value: string}
     */
    private function validateNumber(string $optionUuid, array $entry, ?object $rule): array
    {
        $present = $this->presentValueFields($entry);

        if ($present !== ['numeric_value' => 'numeric_value']) {
            return ['error' => "Option {$optionUuid} requires exactly one numeric_value."];
        }

        $raw = $entry['numeric_value'];

        if ((! is_int($raw) && ! is_float($raw) && ! is_string($raw)) || ! is_numeric($raw)) {
            return ['error' => "Option {$optionUuid} numeric_value must be a number."];
        }

        if ($rule === null) {
            return ['error' => "Option {$optionUuid} has no configured numeric rule."];
        }

        $value = bcadd((string) $raw, '0', 6);

        if (bccomp($value, $rule->minimum_value, 6) < 0 || bccomp($value, $rule->maximum_value, 6) > 0) {
            return ['error' => "Option {$optionUuid} numeric_value must be between {$rule->minimum_value} and {$rule->maximum_value}."];
        }

        $decimals = str_contains((string) $raw, '.') ? strlen(explode('.', (string) $raw)[1]) : 0;

        if ($decimals > (int) $rule->decimal_places) {
            return ['error' => "Option {$optionUuid} numeric_value allows at most {$rule->decimal_places} decimal places."];
        }

        $diff = bcsub($value, $rule->minimum_value, 10);
        $steps = bcdiv($diff, $rule->step_value, 10);
        $stepsRounded = bcadd($steps, '0', 0);
        $remainder = ltrim(bcsub($steps, $stepsRounded, 10), '-');

        if (bccomp($remainder, '0.000001', 10) > 0 && bccomp(bcsub('1', $remainder, 10), '0.000001', 10) > 0) {
            return ['error' => "Option {$optionUuid} numeric_value does not match the allowed step of {$rule->step_value}."];
        }

        return ['numeric_value' => $value];
    }

    /**
     * @return array{error: string}|array{boolean_value: bool}
     */
    private function validateBoolean(string $optionUuid, array $entry): array
    {
        $present = $this->presentValueFields($entry);

        if ($present !== ['boolean_value' => 'boolean_value']) {
            return ['error' => "Option {$optionUuid} requires exactly one boolean_value."];
        }

        if (! is_bool($entry['boolean_value'])) {
            return ['error' => "Option {$optionUuid} boolean_value must be true or false."];
        }

        return ['boolean_value' => $entry['boolean_value']];
    }

    /**
     * @param  array<int, string>  $activeChoiceUuids
     * @return array{error: string}|array{choice_uuids: array<int, string>}
     */
    private function validateSelect(string $optionUuid, array $entry, ?object $rule, array $activeChoiceUuids): array
    {
        $present = $this->presentValueFields($entry);

        if ($present !== ['choice_uuids' => 'choice_uuids']) {
            return ['error' => "Option {$optionUuid} requires exactly one choice_uuids array."];
        }

        $choiceUuids = $entry['choice_uuids'];

        foreach ($choiceUuids as $choiceUuid) {
            if (! is_string($choiceUuid)) {
                return ['error' => "Option {$optionUuid} choice_uuids must be strings."];
            }
        }

        if (count($choiceUuids) !== count(array_unique($choiceUuids))) {
            return ['error' => "Option {$optionUuid} choice_uuids must not contain duplicates."];
        }

        foreach ($choiceUuids as $choiceUuid) {
            if (! in_array($choiceUuid, $activeChoiceUuids, true)) {
                return ['error' => "Choice {$choiceUuid} is not a valid, active choice for option {$optionUuid}."];
            }
        }

        if ($rule === null) {
            return ['error' => "Option {$optionUuid} has no configured selection rule."];
        }

        $count = count($choiceUuids);

        if ($count < (int) $rule->minimum_selections || $count > (int) $rule->maximum_selections) {
            return ['error' => "Option {$optionUuid} requires between {$rule->minimum_selections} and {$rule->maximum_selections} choice(s)."];
        }

        return ['choice_uuids' => array_values($choiceUuids)];
    }
}
