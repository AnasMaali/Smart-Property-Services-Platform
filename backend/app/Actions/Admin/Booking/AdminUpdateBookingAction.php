<?php

namespace App\Actions\Admin\Booking;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Booking\BookingStatuses;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AdminUpdateBookingAction
{
    use BuildsCartResult;

    /**
     * Fields that remain legitimately editable after Booking creation.
     *
     * Nothing financial is mutable here.
     *
     * @var array<int, string>
     */
    private const EDITABLE_LOCATION_FIELDS = [
        'street_name',
        'address_line',
        'building_name_or_number',
        'floor_number',
        'unit_number',
        'nearby_landmark',
        'additional_location_notes',
        'visit_contact_phone',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function handle(
        Request $request,
        User $actor,
        string $bookingUuid,
        array $input,
    ): array {
        try {
            $bookingId = UuidBinary::toBinary($bookingUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Booking not found.');
        }

        return DB::transaction(function () use (
            $request,
            $actor,
            $bookingId,
            $bookingUuid,
            $input,
        ): array {
            /*
             * Booking is the root lock.
             */
            $booking = DB::table('bookings')
                ->where('id', $bookingId)
                ->lockForUpdate()
                ->first();

            if ($booking === null) {
                return $this->notFound('Booking not found.');
            }

            $status = BookingStatuses::code(
                (int) $booking->status_id
            );

            /*
             * Historical/terminal Bookings must remain frozen.
             */
            if (in_array(
                $status,
                ['COMPLETED', 'CANCELLED'],
                true
            )) {
                return $this->conflict(
                    'A completed or cancelled Booking cannot be edited.'
                );
            }

            $location = DB::table('booking_locations')
                ->where('booking_id', $bookingId)
                ->lockForUpdate()
                ->first();

            if ($location === null) {
                return $this->conflict(
                    'Booking location data is missing.'
                );
            }

            $updates = [];
            $oldValues = [];
            $newValues = [];

            foreach (self::EDITABLE_LOCATION_FIELDS as $field) {
                if (! array_key_exists($field, $input)) {
                    continue;
                }

                $newValue = $input[$field];

                if (is_string($newValue)) {
                    $newValue = trim($newValue);

                    if ($newValue === '') {
                        $newValue = null;
                    }
                }

                /*
                 * Required fields may never become null.
                 */
                if (
                    in_array(
                        $field,
                        [
                            'street_name',
                            'address_line',
                            'building_name_or_number',
                            'visit_contact_phone',
                        ],
                        true
                    )
                    && $newValue === null
                ) {
                    return $this->unprocessable(
                        "{$field} cannot be empty."
                    );
                }

                $oldValue = $location->{$field};

                if ($oldValue === $newValue) {
                    continue;
                }

                $updates[$field] = $newValue;

                $oldValues[$field] = $oldValue;
                $newValues[$field] = $newValue;
            }

            if ($updates === []) {
                return $this->ok(
                    200,
                    'Booking already contains these values.',
                    [
                        'booking' => [
                            'uuid' => $bookingUuid,
                            'status' => $status,
                        ],
                    ]
                );
            }

            $timestamp = now()->format(
                'Y-m-d H:i:s.u'
            );

            $updates['updated_at'] = $timestamp;

            DB::table('booking_locations')
                ->where('booking_id', $bookingId)
                ->update($updates);

            AdminAuditLogger::record(
                request: $request,
                actor: $actor,
                actionCode: 'BOOKING_UPDATED',
                entityType: 'BOOKING',
                entityIdentifier: $bookingUuid,
                newValues: $newValues,
                oldValues: $oldValues,
            );

            return $this->ok(
                200,
                'Booking updated successfully.',
                [
                    'booking' => [
                        'uuid' => $bookingUuid,
                        'status' => $status,
                    ],
                ]
            );
        });
    }
}
