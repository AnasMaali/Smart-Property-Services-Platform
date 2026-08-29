<?php

namespace App\Support\Notifications;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Assembles the exact, safe operational content of a NEW-ASSIGNMENT
 * WhatsApp notification from already-committed Booking/Booking Item/
 * Technician data - never anything client-supplied, never anything from
 * `payment_attempts`/pricing/Admin-audit/reconciliation data (see the
 * field-by-field exclusion list below). Read-only: never locks, never
 * mutates, and never calls a provider - purely a function of
 * (booking_item_id, technician_id) at the moment of assignment.
 *
 * Deliberately excludes: Stripe/PaymentIntent/refund data, internal
 * pricing breakdown, Admin audit values, reconciliation data, internal
 * database UUIDs, customer email (no proven operational need for a
 * Technician to have it), and any internal Admin note not intended for a
 * Technician. What a Technician needs: WHAT (service/options), WHEN
 * (appointment day/time, Asia/Dubai), WHERE (full visit address), WHO TO
 * CONTACT (customer name + visit contact phone) - nothing else.
 */
final class TechnicianJobNotificationContent
{
    /**
     * BLUE V1 is a UAE-only operation - every human-facing appointment
     * date/time in a Technician notification is rendered in this fixed
     * timezone, independent of `config('cancellation.timezone')` (a
     * separate, refund-policy-specific setting this feature must not
     * couple to).
     */
    private const DISPLAY_TIMEZONE = 'Asia/Dubai';

    /**
     * @return array<string, string> Flat, already-safe template
     *                               variable map - used both as the
     *                               Meta template's positional parameter
     *                               source (see
     *                               App\Actions\Notifications\
     *                               SendTechnicianNotificationAction) and
     *                               to render the "log" driver's
     *                               human-readable text below.
     */
    public static function forNewAssignment(string $bookingItemIdBinary, string $technicianIdBinary): array
    {
        $item = DB::table('booking_items')->where('id', $bookingItemIdBinary)->first([
            'id', 'booking_id', 'service_name_snapshot', 'quantity',
        ]);

        $booking = DB::table('bookings')->where('id', $item->booking_id)->first(['id', 'booking_number', 'cart_id', 'appointment_slot_id']);

        $technician = DB::table('technicians')->where('id', $technicianIdBinary)->first(['full_name']);

        $customer = DB::table('carts')
            ->join('users', 'users.id', '=', 'carts.customer_user_id')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->where('carts.id', $booking->cart_id)
            ->first(['user_profiles.full_name']);

        $location = DB::table('booking_locations')->where('booking_id', $booking->id)->first();

        $slot = DB::table('appointment_slots')
            ->join('appointment_time_windows', 'appointment_time_windows.id', '=', 'appointment_slots.time_window_id')
            ->where('appointment_slots.id', $booking->appointment_slot_id)
            ->first(['appointment_slots.starts_at', 'appointment_slots.ends_at', 'appointment_time_windows.name as window_name']);

        $startsAtLocal = Carbon::parse($slot->starts_at, config('app.timezone'))->setTimezone(self::DISPLAY_TIMEZONE);
        $endsAtLocal = Carbon::parse($slot->ends_at, config('app.timezone'))->setTimezone(self::DISPLAY_TIMEZONE);

        return [
            'technician_name' => (string) $technician->full_name,
            'booking_number' => (string) $booking->booking_number,
            'service_name' => (string) $item->service_name_snapshot,
            'service_details' => self::serviceDetails($item, $bookingItemIdBinary),
            'appointment_date' => $startsAtLocal->format('D, j M Y'),
            'appointment_start_time' => $startsAtLocal->format('g:i A'),
            'appointment_end_time' => $endsAtLocal->format('g:i A'),
            'time_window' => (string) $slot->window_name,
            'customer_name' => $customer?->full_name ?? 'Customer',
            'visit_contact_phone' => $location?->visit_contact_phone ?? '',
            'property_type' => $location?->property_type_name_snapshot ?? '',
            'building' => (string) ($location?->building_name_or_number ?? ''),
            'floor' => (string) ($location?->floor_number ?? ''),
            'unit' => (string) ($location?->unit_number ?? ''),
            'street' => (string) ($location?->street_name ?? ''),
            'area' => (string) ($location?->area_name_snapshot ?? ''),
            'city' => (string) ($location?->city_name_snapshot ?? ''),
            'landmark' => (string) ($location?->nearby_landmark ?? ''),
            'location_notes' => (string) ($location?->additional_location_notes ?? ''),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function forAssignmentRemoved(string $bookingItemIdBinary): array
    {
        $item = DB::table('booking_items')->where('id', $bookingItemIdBinary)->first(['booking_id']);
        $bookingNumber = DB::table('bookings')->where('id', $item->booking_id)->value('booking_number');

        // Deliberately does NOT include the new Technician's identity -
        // the released Technician has no operational need to know who
        // replaced them (BLUE V1 WhatsApp spec section 9).
        return ['booking_number' => (string) $bookingNumber];
    }

    /**
     * "2x Deep clean" plus a short, clean summary of selected options/
     * choices - never blank/ugly placeholder text when none exist.
     */
    private static function serviceDetails(object $item, string $bookingItemIdBinary): string
    {
        $parts = [((int) $item->quantity).'x '.$item->service_name_snapshot];

        $optionNames = DB::table('booking_item_option_selections')
            ->where('booking_item_id', $bookingItemIdBinary)
            ->pluck('option_name_snapshot')
            ->all();

        $choiceNames = DB::table('booking_item_option_choice_selections')
            ->where('booking_item_id', $bookingItemIdBinary)
            ->pluck('choice_name_snapshot')
            ->all();

        $extras = array_merge($optionNames, $choiceNames);

        if ($extras !== []) {
            $parts[] = implode(', ', $extras);
        }

        return implode(' - ', $parts);
    }

    /**
     * Renders the flat field map from forNewAssignment() into the exact
     * human-readable block the "log" driver writes (and every future
     * driver's `renderedText` for operator/debug visibility) - clean
     * fallback formatting: an absent floor/unit/landmark/notes line is
     * omitted entirely rather than shown as an empty/ugly placeholder.
     *
     * @param  array<string, string>  $fields
     */
    public static function renderAssignmentText(array $fields): string
    {
        $lines = [
            'BLUE | New Service Assignment',
            '',
            "Hello {$fields['technician_name']},",
            '',
            'A new service has been assigned to you.',
            '',
            'Booking',
            $fields['booking_number'],
            '',
            'Service',
            $fields['service_details'],
            '',
            'Date',
            $fields['appointment_date'],
            '',
            'Time',
            "{$fields['appointment_start_time']} - {$fields['appointment_end_time']} ({$fields['time_window']})",
            '',
            'Customer',
            $fields['customer_name'],
            '',
            'Contact',
            $fields['visit_contact_phone'],
            '',
            'Location',
        ];

        foreach (self::locationLines($fields) as $line) {
            $lines[] = $line;
        }

        if ($fields['landmark'] !== '') {
            $lines[] = '';
            $lines[] = 'Nearby landmark';
            $lines[] = $fields['landmark'];
        }

        if ($fields['location_notes'] !== '') {
            $lines[] = '';
            $lines[] = 'Location notes';
            $lines[] = $fields['location_notes'];
        }

        $lines[] = '';
        $lines[] = 'Please arrive during the scheduled appointment window.';
        $lines[] = '';
        $lines[] = 'BLUE';

        return implode("\n", $lines);
    }

    /**
     * The deterministic, always-non-empty positional parameter list for
     * the Meta `WHATSAPP_ASSIGNMENT_TEMPLATE` Utility template (see
     * docs/handoff/technician-whatsapp-v1.md for the exact approved body
     * this order must match) - Meta rejects an empty template parameter
     * value outright, so every field here is composed to always resolve
     * to non-empty text, never a blank optional field passed through
     * directly.
     *
     * @param  array<string, string>  $fields
     * @return array<int, string>
     */
    public static function assignmentTemplateParameters(array $fields): array
    {
        return [
            $fields['technician_name'],
            $fields['booking_number'],
            $fields['service_details'],
            "{$fields['appointment_date']} - {$fields['appointment_start_time']} to {$fields['appointment_end_time']}",
            trim("{$fields['customer_name']} - {$fields['visit_contact_phone']}", ' -'),
            self::locationSummary($fields),
        ];
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<int, string>
     */
    public static function assignmentRemovedTemplateParameters(array $fields): array
    {
        return [$fields['booking_number']];
    }

    /**
     * One comma-joined address line - always non-empty, since
     * property_type/building/street/area/city are the location's own
     * required fields (booking_locations has no fully-optional address).
     * Landmark/notes are intentionally folded in only when present,
     * rather than ever passed as a separate blank template parameter.
     *
     * @param  array<string, string>  $fields
     */
    private static function locationSummary(array $fields): string
    {
        $parts = array_filter([
            $fields['property_type'],
            $fields['building'],
            array_filter([
                $fields['floor'] === '' ? null : "Floor {$fields['floor']}",
                $fields['unit'] === '' ? null : "Unit {$fields['unit']}",
            ]) === [] ? null : implode(' - ', array_filter([
                $fields['floor'] === '' ? null : "Floor {$fields['floor']}",
                $fields['unit'] === '' ? null : "Unit {$fields['unit']}",
            ])),
            $fields['street'],
            $fields['area'],
            $fields['city'],
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        $summary = implode(', ', $parts);

        if ($fields['landmark'] !== '') {
            $summary .= " (near {$fields['landmark']})";
        }

        return $summary === '' ? 'Address on file' : $summary;
    }

    public static function renderAssignmentRemovedText(array $fields): string
    {
        return implode("\n", [
            'BLUE',
            '',
            "Booking {$fields['booking_number']} is no longer assigned to you.",
            '',
            'No action is required.',
        ]);
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<int, string>
     */
    private static function locationLines(array $fields): array
    {
        $lines = [];

        if ($fields['property_type'] !== '') {
            $lines[] = $fields['property_type'];
        }

        if ($fields['building'] !== '') {
            $lines[] = $fields['building'];
        }

        $floorUnit = array_filter([
            $fields['floor'] === '' ? null : "Floor {$fields['floor']}",
            $fields['unit'] === '' ? null : "Unit {$fields['unit']}",
        ]);

        if ($floorUnit !== []) {
            $lines[] = implode(' - ', $floorUnit);
        }

        if ($fields['street'] !== '') {
            $lines[] = $fields['street'];
        }

        $areaCity = array_filter([$fields['area'], $fields['city']]);

        if ($areaCity !== []) {
            $lines[] = implode(', ', $areaCity);
        }

        return $lines === [] ? ['Address on file'] : $lines;
    }
}
