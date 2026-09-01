<?php

namespace App\Support\WhatsApp;

use Illuminate\Support\Carbon;

/**
 * BLUE V1 Simple WhatsApp Handoff - renders the exact, safe human-readable
 * WhatsApp message text for a Technician (new assignment / removal) or
 * Customer (technician assigned / technician changed) handoff link. Pure
 * text rendering only - never queries the database itself; every field it
 * reads must already have been resolved server-side (App\Support\Admin\
 * AdminBookingPresenter) from already-committed Booking/Booking Item/
 * Technician/Payment data, never anything client-supplied.
 *
 * Deliberately excludes: Stripe/PaymentIntent/refund data, internal
 * pricing breakdown, Admin audit values, internal database UUIDs, and any
 * internal Admin note - a Technician gets WHAT/WHEN/WHERE/WHO TO CONTACT
 * only; a Customer additionally gets the historical paid amount (never
 * Stripe/provider internals).
 */
final class WhatsAppMessagePresenter
{
    /**
     * BLUE V1 is a UAE-only operation - every human-facing appointment
     * date/time is rendered in this fixed timezone, independent of any
     * other timezone setting.
     */
    private const DISPLAY_TIMEZONE = 'Asia/Dubai';

    /**
     * @param  array<string, string|null>  $fields  technician_name, booking_number,
     *                                              service_details, starts_at, ends_at,
     *                                              time_window, customer_name,
     *                                              visit_contact_phone, property_type,
     *                                              building, floor, unit, street, area,
     *                                              city, landmark, location_notes
     */
    public static function technicianNewAssignment(array $fields): string
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
            self::displayDate($fields['starts_at']),
            '',
            'Time',
            self::displayTime($fields['starts_at']).' - '.self::displayTime($fields['ends_at']).' ('.$fields['time_window'].')',
            '',
            'Customer',
            $fields['customer_name'],
        ];

        if (($fields['visit_contact_phone'] ?? '') !== '') {
            $lines[] = '';
            $lines[] = 'Contact';
            $lines[] = $fields['visit_contact_phone'];
        }

        $lines[] = '';
        $lines[] = 'Location';

        foreach (self::locationLines($fields) as $line) {
            $lines[] = $line;
        }

        if (($fields['landmark'] ?? '') !== '') {
            $lines[] = '';
            $lines[] = 'Nearby landmark';
            $lines[] = $fields['landmark'];
        }

        if (($fields['location_notes'] ?? '') !== '') {
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
     * Deliberately carries ONLY the booking number - the released
     * Technician has no operational need to know who replaced them, and
     * must never be told the internal reassignment reason or Admin notes.
     */
    public static function technicianRemoved(string $bookingNumber): string
    {
        return implode("\n", [
            'BLUE | Assignment Update',
            '',
            "Booking {$bookingNumber} is no longer assigned to you.",
            '',
            'No action is required.',
        ]);
    }

    /**
     * @param  array<string, string|null>  $fields  booking_number, service_name,
     *                                              technician_name, starts_at, ends_at,
     *                                              time_window, property_type, building,
     *                                              floor, unit, street, area, city,
     *                                              landmark, paid_amount (nullable - a
     *                                              contract-billed Booking has no
     *                                              `payment_attempt_id` to read from)
     */
    public static function customerAssigned(array $fields): string
    {
        return self::customerMessage($fields, 'Your technician has been assigned.', includeThanks: true);
    }

    /**
     * @param  array<string, string|null>  $fields  same shape as customerAssigned()
     */
    public static function customerChanged(array $fields): string
    {
        return self::customerMessage($fields, 'The technician assigned to your booking has been updated.', includeThanks: false);
    }

    /**
     * Service Completion Report handoff (BLUE V1 Service Completion Report) -
     * the Admin has already generated the PDF and downloaded/shared it
     * through the device's native share sheet or a manual attach; this
     * message text never claims the report was sent or received, and never
     * carries financial figures - the customer sees those inside the
     * attached PDF itself, not in a WhatsApp message an Admin could
     * forward without the attachment.
     *
     * @param  array{customer_name: string, booking_number: string, has_photos: bool}  $fields
     */
    public static function completionReportReady(array $fields): string
    {
        $photoLine = $fields['has_photos']
            ? 'Your Service Completion Report is ready and includes the service details and before/after photos.'
            : 'Your Service Completion Report is ready and includes the service details.';

        return implode("\n", [
            "Hello {$fields['customer_name']},",
            '',
            "Your BLUE service for booking {$fields['booking_number']} has been completed.",
            '',
            $photoLine,
            '',
            'Please find the report attached.',
            '',
            'Thank you for choosing BLUE.',
        ]);
    }

    /**
     * @param  array<string, string|null>  $fields
     */
    private static function customerMessage(array $fields, string $intro, bool $includeThanks): string
    {
        $lines = [
            'BLUE',
            '',
            $intro,
            '',
            'Booking',
            $fields['booking_number'],
            '',
            'Service',
            $fields['service_name'],
            '',
            'Technician',
            $fields['technician_name'],
            '',
            'Appointment',
            self::displayDate($fields['starts_at']).', '.self::displayTime($fields['starts_at']).' - '.self::displayTime($fields['ends_at']),
            '',
            'Address',
            self::addressSummary($fields),
            '',
            'Amount paid',
            $fields['paid_amount'] === null ? 'Covered by your service contract' : "{$fields['paid_amount']} AED",
        ];

        if ($includeThanks) {
            $lines[] = '';
            $lines[] = 'Thank you for choosing BLUE.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, string|null>  $fields
     */
    private static function locationLines(array $fields): array
    {
        $lines = [];

        if (($fields['property_type'] ?? '') !== '') {
            $lines[] = $fields['property_type'];
        }

        if (($fields['building'] ?? '') !== '') {
            $lines[] = $fields['building'];
        }

        $floorUnit = self::floorUnitSummary($fields);

        if ($floorUnit !== null) {
            $lines[] = $floorUnit;
        }

        if (($fields['street'] ?? '') !== '') {
            $lines[] = $fields['street'];
        }

        $areaCity = array_filter([$fields['area'] ?? '', $fields['city'] ?? '']);

        if ($areaCity !== []) {
            $lines[] = implode(', ', $areaCity);
        }

        return $lines === [] ? ['Address on file'] : $lines;
    }

    /**
     * One comma-joined address line for the Customer message - the same
     * fields technicianNewAssignment() renders as separate lines, folded
     * into a single summary line here instead.
     *
     * @param  array<string, string|null>  $fields
     */
    private static function addressSummary(array $fields): string
    {
        $parts = array_filter([
            $fields['property_type'] ?? '',
            $fields['building'] ?? '',
            self::floorUnitSummary($fields),
            $fields['street'] ?? '',
            $fields['area'] ?? '',
            $fields['city'] ?? '',
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        $summary = implode(', ', $parts);

        if (($fields['landmark'] ?? '') !== '') {
            $summary .= " (near {$fields['landmark']})";
        }

        return $summary === '' ? 'Address on file' : $summary;
    }

    /**
     * @param  array<string, string|null>  $fields
     */
    private static function floorUnitSummary(array $fields): ?string
    {
        $parts = array_filter([
            ($fields['floor'] ?? '') === '' ? null : "Floor {$fields['floor']}",
            ($fields['unit'] ?? '') === '' ? null : "Unit {$fields['unit']}",
        ]);

        return $parts === [] ? null : implode(' - ', $parts);
    }

    private static function displayDate(string $utcDateTime): string
    {
        return Carbon::parse($utcDateTime, config('app.timezone'))->setTimezone(self::DISPLAY_TIMEZONE)->format('D, j M Y');
    }

    private static function displayTime(string $utcDateTime): string
    {
        return Carbon::parse($utcDateTime, config('app.timezone'))->setTimezone(self::DISPLAY_TIMEZONE)->format('g:i A');
    }
}
