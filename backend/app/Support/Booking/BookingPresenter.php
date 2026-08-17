<?php

namespace App\Support\Booking;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one safe, Flutter-facing Booking JSON shape - mirrors
 * App\Support\Payment\PaymentPresenter / App\Support\Checkout\
 * CheckoutPresenter's conventions (snake_case, UUID strings, ISO-8601
 * datetimes, decimal-string money, explicit nullability). Never exposes
 * `payment_attempt_id`, `checkout_snapshot`/`checkout_snapshot_hash`,
 * pricing-rule internals, or raw binary(16) ids.
 *
 * Every monetary/descriptive value comes from `booking_items` /
 * `booking_locations` - the rows CreateBookingFromSuccessfulPaymentAction
 * wrote once, verbatim, from the frozen `checkout_snapshot`. Nothing here
 * re-reads the live catalog, live pricing, or the customer's current
 * profile/cart - a later service price/name change or profile address
 * edit can never change what this presenter returns for an existing
 * Booking.
 */
final class BookingPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(object $booking): array
    {
        $statusCode = DB::table('booking_statuses')->where('id', $booking->status_id)->value('code');
        $currency = DB::table('currencies')->where('id', $booking->cart_currency_id)->first(['code', 'symbol', 'minor_unit']);
        $sourceCode = DB::table('booking_sources')->where('id', $booking->booking_source_id)->value('code');

        $location = DB::table('booking_locations')->where('booking_id', $booking->id)->first();
        $slot = DB::table('appointment_slots')
            ->join('appointment_time_windows', 'appointment_time_windows.id', '=', 'appointment_slots.time_window_id')
            ->where('appointment_slots.id', $booking->appointment_slot_id)
            ->first(['appointment_slots.id', 'appointment_slots.starts_at', 'appointment_slots.ends_at', 'appointment_time_windows.code as window_code', 'appointment_time_windows.name as window_name']);

        $items = DB::table('booking_items')
            ->join('booking_item_statuses', 'booking_item_statuses.id', '=', 'booking_items.status_id')
            ->where('booking_items.booking_id', $booking->id)
            ->orderBy('booking_items.display_order')
            ->get([
                'booking_items.id',
                'booking_items.service_id',
                'booking_items.service_code_snapshot',
                'booking_items.service_name_snapshot',
                'booking_items.quantity',
                'booking_items.pricing_scheme_version_id',
                'booking_items.base_amount_snapshot',
                'booking_items.pricing_breakdown',
                'booking_items.unit_total_amount',
                'booking_items.line_total_amount',
                'booking_items.completed_at',
                'booking_items.cancelled_at',
                'booking_item_statuses.code as status_code',
            ]);

        $total = '0.000000';
        foreach ($items as $item) {
            $total = bcadd($total, (string) $item->line_total_amount, 6);
        }

        return [
            'uuid' => UuidBinary::toString($booking->id),
            'booking_number' => $booking->booking_number,
            'status' => $statusCode,
            'source' => $sourceCode,
            'contract' => $booking->service_contract_id === null ? null : [
                'contract_uuid' => UuidBinary::toString($booking->service_contract_id),
                'contract_item_uuid' => UuidBinary::toString($booking->service_contract_item_id),
            ],
            'currency' => $currency === null ? null : [
                'code' => $currency->code,
                'symbol' => $currency->symbol,
                'decimal_places' => (int) $currency->minor_unit,
            ],
            'total' => $total,
            'location' => $location === null ? null : self::locationPayload($location),
            'appointment' => $slot === null ? null : self::appointmentPayload($slot),
            'items' => $items->map(self::itemPayload(...))->all(),
            'created_at' => Carbon::parse($booking->created_at)->toIso8601String(),
            'status_changed_at' => Carbon::parse($booking->status_changed_at)->toIso8601String(),
            'completed_at' => $booking->completed_at === null ? null : Carbon::parse($booking->completed_at)->toIso8601String(),
            'cancelled_at' => $booking->cancelled_at === null ? null : Carbon::parse($booking->cancelled_at)->toIso8601String(),
            'refund_due' => $statusCode === 'CANCELLED' ? self::refundDuePayload($booking) : null,
        ];
    }

    /**
     * Refund eligibility for an already-CANCELLED Booking only - read
     * verbatim from the historical snapshot
     * App\Actions\Booking\CancelBookingAction persisted at the moment of
     * the Booking's first real cancellation
     * (`bookings.cancellation_refund_percentage` /
     * `cancellation_refund_amount`). Never recomputed here - a later change
     * to `config('cancellation.*')` must never change what an
     * already-cancelled Booking is shown to owe, so this never calls
     * App\Support\Booking\RefundEligibilityCalculator.
     *
     * @return array{percentage: int, amount: string, execution: 'MANUAL'}|null
     */
    private static function refundDuePayload(object $booking): ?array
    {
        if ($booking->cancellation_refund_percentage === null || $booking->cancellation_refund_amount === null) {
            return null;
        }

        return [
            'percentage' => (int) $booking->cancellation_refund_percentage,
            'amount' => (string) $booking->cancellation_refund_amount,
            'execution' => 'MANUAL',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function locationPayload(object $location): array
    {
        return [
            'property_type_name' => $location->property_type_name_snapshot,
            'other_property_type_name' => $location->other_property_type_name,
            'country_name' => $location->country_name_snapshot,
            'city_name' => $location->city_name_snapshot,
            'area_name' => $location->area_name_snapshot,
            'street_name' => $location->street_name,
            'address_line' => $location->address_line,
            'building_name_or_number' => $location->building_name_or_number,
            'floor_number' => $location->floor_number,
            'unit_number' => $location->unit_number,
            'nearby_landmark' => $location->nearby_landmark,
            'additional_location_notes' => $location->additional_location_notes,
            'visit_contact_phone' => $location->visit_contact_phone,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function appointmentPayload(object $slot): array
    {
        return [
            'slot' => [
                'uuid' => UuidBinary::toString($slot->id),
                'starts_at' => Carbon::parse($slot->starts_at)->toIso8601String(),
                'ends_at' => Carbon::parse($slot->ends_at)->toIso8601String(),
                'time_window' => [
                    'code' => $slot->window_code,
                    'name' => $slot->window_name,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function itemPayload(object $item): array
    {
        return [
            'uuid' => UuidBinary::toString($item->id),
            'service' => [
                'uuid' => UuidBinary::toString($item->service_id),
                'code' => $item->service_code_snapshot,
                'name' => $item->service_name_snapshot,
            ],
            'quantity' => (int) $item->quantity,
            'status' => $item->status_code,
            'completed_at' => $item->completed_at === null ? null : Carbon::parse($item->completed_at)->toIso8601String(),
            'cancelled_at' => $item->cancelled_at === null ? null : Carbon::parse($item->cancelled_at)->toIso8601String(),
            'pricing' => [
                'pricing_scheme_version_uuid' => UuidBinary::toString($item->pricing_scheme_version_id),
                'base_amount' => $item->base_amount_snapshot,
                'adjustments' => json_decode((string) $item->pricing_breakdown, true) ?? [],
                'unit_total' => $item->unit_total_amount,
                'line_total' => $item->line_total_amount,
            ],
        ];
    }
}
