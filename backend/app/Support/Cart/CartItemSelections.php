<?php

namespace App\Support\Cart;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * Persists and reloads cart_item_option_selections /
 * cart_item_option_choice_selections rows. Scalar option values (TEXT,
 * NUMBER, BOOLEAN) live in the first table; SINGLE_SELECT/MULTI_SELECT
 * choices live in the second, joined back to their owning service_option
 * through service_option_choices since the choice-selections table itself
 * only stores the choice id.
 */
final class CartItemSelections
{
    /**
     * @param  array<int, array<string, mixed>>  $normalizedOptions  CartSelectionValidator::validate()['options'] output.
     */
    public static function persist(string $cartItemIdBinary, array $normalizedOptions): void
    {
        $now = now();

        foreach ($normalizedOptions as $option) {
            if (in_array($option['type'], ['SINGLE_SELECT', 'MULTI_SELECT'], true)) {
                foreach ($option['choice_uuids'] as $choiceUuid) {
                    DB::table('cart_item_option_choice_selections')->insert([
                        'cart_item_id' => $cartItemIdBinary,
                        'service_option_choice_id' => UuidBinary::toBinary($choiceUuid),
                        'created_at' => $now,
                    ]);
                }

                continue;
            }

            DB::table('cart_item_option_selections')->insert([
                'cart_item_id' => $cartItemIdBinary,
                'service_option_id' => $option['option_id_binary'],
                'numeric_value' => $option['type'] === 'NUMBER' ? $option['numeric_value'] : null,
                'boolean_value' => $option['type'] === 'BOOLEAN' ? ($option['boolean_value'] ? 1 : 0) : null,
                'text_value' => $option['type'] === 'TEXT' ? $option['text_value'] : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Full delete + reinsert, meant to run inside the caller's transaction.
     *
     * @param  array<int, array<string, mixed>>  $normalizedOptions
     */
    public static function replace(string $cartItemIdBinary, array $normalizedOptions): void
    {
        DB::table('cart_item_option_selections')->where('cart_item_id', $cartItemIdBinary)->delete();
        DB::table('cart_item_option_choice_selections')->where('cart_item_id', $cartItemIdBinary)->delete();

        self::persist($cartItemIdBinary, $normalizedOptions);
    }

    /**
     * Reconstructs the request-shaped `options` array (option_uuid + one
     * value field) from persisted rows, for PATCH revalidation when the
     * caller did not submit `options`, and for safe item-response display.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function loadAsInput(string $cartItemIdBinary): array
    {
        $options = [];

        $scalarRows = DB::table('cart_item_option_selections')
            ->where('cart_item_id', $cartItemIdBinary)
            ->get(['service_option_id', 'numeric_value', 'boolean_value', 'text_value']);

        foreach ($scalarRows as $row) {
            $entry = ['option_uuid' => UuidBinary::toString($row->service_option_id)];

            if ($row->numeric_value !== null) {
                $entry['numeric_value'] = $row->numeric_value;
            } elseif ($row->boolean_value !== null) {
                $entry['boolean_value'] = (bool) $row->boolean_value;
            } elseif ($row->text_value !== null) {
                $entry['text_value'] = $row->text_value;
            }

            $options[] = $entry;
        }

        $choiceRows = DB::table('cart_item_option_choice_selections')
            ->join('service_option_choices', 'service_option_choices.id', '=', 'cart_item_option_choice_selections.service_option_choice_id')
            ->where('cart_item_option_choice_selections.cart_item_id', $cartItemIdBinary)
            ->get(['service_option_choices.service_option_id', 'cart_item_option_choice_selections.service_option_choice_id']);

        $choicesByOptionUuid = $choiceRows->groupBy(fn ($row) => UuidBinary::toString($row->service_option_id));

        foreach ($choicesByOptionUuid as $optionUuid => $rows) {
            $options[] = [
                'option_uuid' => $optionUuid,
                'choice_uuids' => $rows->map(fn ($row) => UuidBinary::toString($row->service_option_choice_id))->values()->all(),
            ];
        }

        return $options;
    }

    /**
     * Builds the PricingEngine-ready selections map directly from persisted
     * rows, independent of the current catalog schema - GET must be able to
     * reprice a cart item even if its selected option has since been
     * deactivated.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function loadPricingSelections(string $cartItemIdBinary): array
    {
        $selections = [];

        $scalarRows = DB::table('cart_item_option_selections')
            ->where('cart_item_id', $cartItemIdBinary)
            ->get(['service_option_id', 'numeric_value', 'boolean_value', 'text_value']);

        foreach ($scalarRows as $row) {
            $type = $row->numeric_value !== null ? 'NUMERIC' : ($row->boolean_value !== null ? 'BOOLEAN' : 'TEXT');

            $selections[UuidBinary::toString($row->service_option_id)] = [
                'type' => $type,
                'numeric_value' => $row->numeric_value,
                'boolean_value' => $row->boolean_value === null ? null : (bool) $row->boolean_value,
                'text_value' => $row->text_value,
                'choice_ids' => [],
            ];
        }

        $choiceRows = DB::table('cart_item_option_choice_selections')
            ->join('service_option_choices', 'service_option_choices.id', '=', 'cart_item_option_choice_selections.service_option_choice_id')
            ->where('cart_item_option_choice_selections.cart_item_id', $cartItemIdBinary)
            ->get(['service_option_choices.service_option_id', 'cart_item_option_choice_selections.service_option_choice_id']);

        $choicesByOptionUuid = $choiceRows->groupBy(fn ($row) => UuidBinary::toString($row->service_option_id));

        foreach ($choicesByOptionUuid as $optionUuid => $rows) {
            $selections[$optionUuid] = [
                'type' => 'CHOICE',
                'numeric_value' => null,
                'boolean_value' => null,
                'text_value' => null,
                'choice_ids' => $rows->map(fn ($row) => UuidBinary::toString($row->service_option_choice_id))->values()->all(),
            ];
        }

        return $selections;
    }
}
