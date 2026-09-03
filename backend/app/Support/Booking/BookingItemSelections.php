<?php

namespace App\Support\Booking;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * Copies option selections from a cart item into booking-item-level snapshot
 * tables (`booking_item_option_selections` and
 * `booking_item_option_choice_selections`). Every value written here is a
 * frozen historical snapshot: option names/codes, choice names/codes,
 * measurement unit details, and the additional_unit_amount are all resolved
 * from the live catalog at the moment of booking creation and stored
 * verbatim - a later catalog edit can never change what these rows contain.
 *
 * Called exclusively from CreateBookingFromSuccessfulPaymentAction, always
 * inside the same locked transaction that writes booking_items.
 */
final class BookingItemSelections
{
    /**
     * Copy all persisted option selections from a cart_item to the
     * corresponding booking_item's snapshot tables.
     */
    public static function copyFromCartItem(string $bookingItemIdBinary, string $cartItemIdBinary): void
    {
        $now = now()->format('Y-m-d H:i:s.u');

        self::copyScalarSelections($bookingItemIdBinary, $cartItemIdBinary, $now);
        self::copyChoiceSelections($bookingItemIdBinary, $cartItemIdBinary, $now);
    }

    /**
     * Copies NUMBER and BOOLEAN option selections with full catalog snapshots.
     */
    private static function copyScalarSelections(string $bookingItemIdBinary, string $cartItemIdBinary, string $now): void
    {
        $scalarRows = DB::table('cart_item_option_selections')
            ->where('cart_item_id', $cartItemIdBinary)
            ->get(['service_option_id', 'numeric_value', 'boolean_value', 'text_value']);

        foreach ($scalarRows as $row) {
            $option = DB::table('service_options')
                ->join('service_option_types', 'service_option_types.id', '=', 'service_options.option_type_id')
                ->where('service_options.id', $row->service_option_id)
                ->first(['service_options.code', 'service_options.name', 'service_option_types.code as type_code']);

            if ($option === null) {
                continue;
            }

            $measurementUnit = null;
            $measurementUnitId = null;

            if ($option->type_code === 'NUMBER') {
                $numericRule = DB::table('service_option_numeric_rules')
                    ->where('service_option_id', $row->service_option_id)
                    ->first(['measurement_unit_id']);

                if ($numericRule !== null && $numericRule->measurement_unit_id !== null) {
                    $measurementUnitId = $numericRule->measurement_unit_id;
                    $measurementUnit = DB::table('measurement_units')
                        ->where('id', $numericRule->measurement_unit_id)
                        ->first(['code', 'name', 'symbol']);
                }
            }

            // The booking table only supports NUMBER and BOOLEAN types for
            // scalar selections (enforced by chk_booking_option_selection_type).
            // TEXT options are part of the cart flow but do not have a
            // corresponding booking snapshot column - they are preserved in
            // the checkout_snapshot JSON on payment_attempts instead.
            if (! in_array($option->type_code, ['NUMBER', 'BOOLEAN'], true)) {
                continue;
            }

            DB::table('booking_item_option_selections')->insert([
                'booking_item_id' => $bookingItemIdBinary,
                'service_option_id' => $row->service_option_id,
                'measurement_unit_id' => $measurementUnitId,
                'option_code_snapshot' => $option->code,
                'option_name_snapshot' => $option->name,
                'option_type_code_snapshot' => $option->type_code,
                'numeric_value' => $row->numeric_value,
                'boolean_value' => $row->boolean_value,
                'measurement_unit_code_snapshot' => $measurementUnit?->code,
                'measurement_unit_name_snapshot' => $measurementUnit?->name,
                'measurement_unit_symbol_snapshot' => $measurementUnit?->symbol,
                'additional_unit_amount_snapshot' => '0.000000',
                'created_at' => $now,
            ]);
        }
    }

    /**
     * Copies SINGLE_SELECT and MULTI_SELECT choice selections with full
     * catalog snapshots.
     */
    private static function copyChoiceSelections(string $bookingItemIdBinary, string $cartItemIdBinary, string $now): void
    {
        $choiceRows = DB::table('cart_item_option_choice_selections')
            ->where('cart_item_id', $cartItemIdBinary)
            ->get(['service_option_choice_id']);

        foreach ($choiceRows as $choiceRow) {
            $choice = DB::table('service_option_choices')
                ->where('id', $choiceRow->service_option_choice_id)
                ->first(['id', 'service_option_id', 'code', 'name', 'description']);

            if ($choice === null) {
                continue;
            }

            $option = DB::table('service_options')
                ->join('service_option_types', 'service_option_types.id', '=', 'service_options.option_type_id')
                ->where('service_options.id', $choice->service_option_id)
                ->first(['service_options.code as option_code', 'service_options.name as option_name', 'service_option_types.code as type_code']);

            if ($option === null) {
                continue;
            }

            DB::table('booking_item_option_choice_selections')->insert([
                'booking_item_id' => $bookingItemIdBinary,
                'service_option_choice_id' => $choiceRow->service_option_choice_id,
                'option_code_snapshot' => $option->option_code,
                'option_name_snapshot' => $option->option_name,
                'option_type_code_snapshot' => $option->type_code,
                'choice_code_snapshot' => $choice->code,
                'choice_name_snapshot' => $choice->name,
                'choice_description_snapshot' => $choice->description,
                'additional_unit_amount_snapshot' => '0.000000',
                'created_at' => $now,
            ]);
        }
    }

    /**
     * Loads persisted booking option selections for presentation.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function loadForPresentation(string $bookingItemIdBinary): array
    {
        $options = [];

        $scalarRows = DB::table('booking_item_option_selections')
            ->where('booking_item_id', $bookingItemIdBinary)
            ->get([
                'service_option_id',
                'option_code_snapshot',
                'option_name_snapshot',
                'option_type_code_snapshot',
                'numeric_value',
                'boolean_value',
                'measurement_unit_code_snapshot',
                'measurement_unit_name_snapshot',
                'measurement_unit_symbol_snapshot',
            ]);

        foreach ($scalarRows as $row) {
            $entry = [
                'option_uuid' => UuidBinary::toString($row->service_option_id),
                'code' => $row->option_code_snapshot,
                'name' => $row->option_name_snapshot,
                'type' => $row->option_type_code_snapshot,
            ];

            if ($row->option_type_code_snapshot === 'NUMBER') {
                $entry['numeric_value'] = $row->numeric_value;
                $entry['measurement_unit'] = $row->measurement_unit_code_snapshot === null ? null : [
                    'code' => $row->measurement_unit_code_snapshot,
                    'name' => $row->measurement_unit_name_snapshot,
                    'symbol' => $row->measurement_unit_symbol_snapshot,
                ];
            } elseif ($row->option_type_code_snapshot === 'BOOLEAN') {
                $entry['boolean_value'] = (bool) $row->boolean_value;
            }

            $options[] = $entry;
        }

        $choiceRows = DB::table('booking_item_option_choice_selections')
            ->leftJoin('service_option_choices', 'service_option_choices.id', '=', 'booking_item_option_choice_selections.service_option_choice_id')
            ->where('booking_item_option_choice_selections.booking_item_id', $bookingItemIdBinary)
            ->get([
                'booking_item_option_choice_selections.service_option_choice_id',
                'booking_item_option_choice_selections.option_code_snapshot',
                'booking_item_option_choice_selections.option_name_snapshot',
                'booking_item_option_choice_selections.option_type_code_snapshot',
                'booking_item_option_choice_selections.choice_code_snapshot',
                'booking_item_option_choice_selections.choice_name_snapshot',
                'booking_item_option_choice_selections.choice_description_snapshot',
                'service_option_choices.service_option_id',
            ]);

        // Group choices by their parent option (identified by option_code_snapshot).
        $choicesByOptionCode = [];
        foreach ($choiceRows as $row) {
            $key = $row->option_code_snapshot;
            if (! isset($choicesByOptionCode[$key])) {
                $choicesByOptionCode[$key] = [
                    'option_uuid' => $row->service_option_id === null ? null : UuidBinary::toString($row->service_option_id),
                    'option_name' => $row->option_name_snapshot,
                    'option_code' => $row->option_code_snapshot,
                    'type' => $row->option_type_code_snapshot,
                    'choices' => [],
                ];
            }
            $choicesByOptionCode[$key]['choices'][] = [
                'uuid' => UuidBinary::toString($row->service_option_choice_id),
                'code' => $row->choice_code_snapshot,
                'name' => $row->choice_name_snapshot,
                'description' => $row->choice_description_snapshot,
            ];
        }

        foreach ($choicesByOptionCode as $group) {
            $options[] = [
                'option_uuid' => $group['option_uuid'],
                'code' => $group['option_code'],
                'name' => $group['option_name'],
                'type' => $group['type'],
                'choices' => $group['choices'],
            ];
        }

        return $options;
    }
}
