<?php

namespace App\Actions\Notifications;

use App\Support\Notifications\Email\CustomerAssignmentEmailContent;
use App\Support\Notifications\OutboundNotificationStatuses;
use App\Support\Notifications\TechnicianJobNotificationContent;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * BLUE V1 Phase B22 - writes exactly one durable EMAIL-channel
 * `outbound_notifications` obligation per (Technician assignment,
 * notification type) pair, mirroring App\Actions\Notifications\
 * CreateTechnicianAssignmentNotificationAction's WhatsApp role exactly -
 * same transactional-outbox placement (called from the SAME $afterMutation
 * callback, inside the SAME assign()/reassign() transaction), same
 * idempotency-key convention, same "never calls the provider, purely a
 * durable-obligation writer" boundary.
 *
 * Four notification types, all EMAIL/never WHATSAPP:
 * - TECHNICIAN_NEW_ASSIGNMENT_EMAIL / TECHNICIAN_ASSIGNMENT_REMOVED_EMAIL
 *   (recipient_type TECHNICIAN) reuse TechnicianJobNotificationContent's
 *   existing, already-safe field set verbatim - never a second, duplicated
 *   assembly of the same safe fields.
 * - CUSTOMER_TECHNICIAN_ASSIGNED_EMAIL / CUSTOMER_TECHNICIAN_CHANGED_EMAIL
 *   (recipient_type CUSTOMER) use App\Support\Notifications\Email\
 *   CustomerAssignmentEmailContent, which additionally carries the
 *   authoritative historical paid amount.
 *
 * Every create* method returns `null` (never throws, never breaks the
 * caller's assignment transaction) when there is no usable email address to
 * durably record - `outbound_notifications.recipient_address_snapshot` is
 * NOT NULL, so an absent Technician email (a nullable column) or an absent
 * resolvable customer leaves nothing safe to insert. This is distinct from
 * an address that exists but is malformed, which IS still recorded (and
 * correctly fails at send time - see App\Actions\Notifications\
 * SendEmailNotificationAction) exactly like an invalid Technician phone
 * number already does for WhatsApp.
 */
final class CreateEmailNotificationAction
{
    public function createTechnicianNewAssignmentEmail(string $assignmentUuid): ?string
    {
        $idempotencyKey = self::idempotencyKey($assignmentUuid, 'TECHNICIAN_NEW_ASSIGNMENT_EMAIL');

        $existing = $this->existingNotificationUuid($idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        $assignmentIdBinary = UuidBinary::toBinary($assignmentUuid);
        $assignment = DB::table('technician_assignments')->where('id', $assignmentIdBinary)->first(['booking_item_id', 'technician_id']);
        $technicianEmail = DB::table('technicians')->where('id', $assignment->technician_id)->value('email');

        if ($technicianEmail === null) {
            return null;
        }

        $item = DB::table('booking_items')->where('id', $assignment->booking_item_id)->first(['booking_id']);
        $fields = TechnicianJobNotificationContent::forNewAssignment($assignment->booking_item_id, $assignment->technician_id);

        return $this->insert(
            notificationType: 'TECHNICIAN_NEW_ASSIGNMENT_EMAIL',
            recipientType: 'TECHNICIAN',
            recipientIdBinary: $assignment->technician_id,
            recipientAddress: (string) $technicianEmail,
            bookingIdBinary: $item->booking_id,
            bookingItemIdBinary: $assignment->booking_item_id,
            technicianAssignmentIdBinary: $assignmentIdBinary,
            idempotencyKey: $idempotencyKey,
            payloadSnapshot: $fields,
        );
    }

    public function createTechnicianAssignmentRemovedEmail(string $releasedAssignmentUuid): ?string
    {
        $idempotencyKey = self::idempotencyKey($releasedAssignmentUuid, 'TECHNICIAN_ASSIGNMENT_REMOVED_EMAIL');

        $existing = $this->existingNotificationUuid($idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        $assignmentIdBinary = UuidBinary::toBinary($releasedAssignmentUuid);
        $assignment = DB::table('technician_assignments')->where('id', $assignmentIdBinary)->first(['booking_item_id', 'technician_id']);
        $technicianEmail = DB::table('technicians')->where('id', $assignment->technician_id)->value('email');

        if ($technicianEmail === null) {
            return null;
        }

        $item = DB::table('booking_items')->where('id', $assignment->booking_item_id)->first(['booking_id']);
        $fields = TechnicianJobNotificationContent::forAssignmentRemoved($assignment->booking_item_id);

        return $this->insert(
            notificationType: 'TECHNICIAN_ASSIGNMENT_REMOVED_EMAIL',
            recipientType: 'TECHNICIAN',
            recipientIdBinary: $assignment->technician_id,
            recipientAddress: (string) $technicianEmail,
            bookingIdBinary: $item->booking_id,
            bookingItemIdBinary: $assignment->booking_item_id,
            technicianAssignmentIdBinary: $assignmentIdBinary,
            idempotencyKey: $idempotencyKey,
            payloadSnapshot: $fields,
        );
    }

    public function createCustomerTechnicianAssignedEmail(string $assignmentUuid): ?string
    {
        return $this->createCustomerEmail($assignmentUuid, 'CUSTOMER_TECHNICIAN_ASSIGNED_EMAIL');
    }

    public function createCustomerTechnicianChangedEmail(string $assignmentUuid): ?string
    {
        return $this->createCustomerEmail($assignmentUuid, 'CUSTOMER_TECHNICIAN_CHANGED_EMAIL');
    }

    private function createCustomerEmail(string $assignmentUuid, string $notificationType): ?string
    {
        $idempotencyKey = self::idempotencyKey($assignmentUuid, $notificationType);

        $existing = $this->existingNotificationUuid($idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        $assignmentIdBinary = UuidBinary::toBinary($assignmentUuid);
        $assignment = DB::table('technician_assignments')->where('id', $assignmentIdBinary)->first(['booking_item_id', 'technician_id']);
        $item = DB::table('booking_items')->where('id', $assignment->booking_item_id)->first(['booking_id']);
        $booking = DB::table('bookings')->where('id', $item->booking_id)->first(['cart_id']);

        $customer = DB::table('carts')
            ->join('users', 'users.id', '=', 'carts.customer_user_id')
            ->where('carts.id', $booking->cart_id)
            ->first(['users.id', 'users.email']);

        if ($customer === null || $customer->email === null || trim((string) $customer->email) === '') {
            // BLUE V1 email spec section 10 - never invent an address, and
            // never let a missing/unresolvable customer break the
            // assignment that triggered this.
            return null;
        }

        $fields = CustomerAssignmentEmailContent::build($assignment->booking_item_id, $assignment->technician_id);

        return $this->insert(
            notificationType: $notificationType,
            recipientType: 'CUSTOMER',
            recipientIdBinary: $customer->id,
            recipientAddress: (string) $customer->email,
            bookingIdBinary: $item->booking_id,
            bookingItemIdBinary: $assignment->booking_item_id,
            technicianAssignmentIdBinary: $assignmentIdBinary,
            idempotencyKey: $idempotencyKey,
            payloadSnapshot: $fields,
        );
    }

    private function existingNotificationUuid(string $idempotencyKey): ?string
    {
        $existing = DB::table('outbound_notifications')->where('idempotency_key', $idempotencyKey)->value('id');

        return $existing === null ? null : UuidBinary::toString($existing);
    }

    /**
     * @param  array<string, string|null>  $payloadSnapshot
     */
    private function insert(
        string $notificationType,
        string $recipientType,
        string $recipientIdBinary,
        string $recipientAddress,
        string $bookingIdBinary,
        string $bookingItemIdBinary,
        string $technicianAssignmentIdBinary,
        string $idempotencyKey,
        array $payloadSnapshot,
    ): string {
        $now = now();
        $timestamp = $now->format('Y-m-d H:i:s.u');
        $notificationUuid = UuidBinary::generate();

        try {
            DB::table('outbound_notifications')->insert([
                'id' => UuidBinary::toBinary($notificationUuid),
                'channel' => 'EMAIL',
                'notification_type' => $notificationType,
                'recipient_type' => $recipientType,
                'recipient_id' => $recipientIdBinary,
                'recipient_address_snapshot' => $recipientAddress,
                'booking_id' => $bookingIdBinary,
                'booking_item_id' => $bookingItemIdBinary,
                'technician_assignment_id' => $technicianAssignmentIdBinary,
                'status_id' => OutboundNotificationStatuses::id('PENDING'),
                'idempotency_key' => $idempotencyKey,
                'payload_snapshot' => json_encode($payloadSnapshot, JSON_THROW_ON_ERROR),
                'attempt_count' => 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->existingNotificationUuid($idempotencyKey);

            if ($existing !== null) {
                return $existing;
            }

            throw new RuntimeException(
                'Outbound email notification insert violated a unique constraint but no resolvable row was found.',
                previous: $exception,
            );
        }

        return $notificationUuid;
    }

    /**
     * Mirrors App\Actions\Notifications\
     * CreateTechnicianAssignmentNotificationAction::idempotencyKey() -
     * the notification_type suffix (already ending in "_EMAIL" for every
     * type this class writes) is what keeps this distinct from the
     * WHATSAPP obligation the SAME assignment uuid may also have.
     */
    private static function idempotencyKey(string $assignmentUuid, string $notificationType): string
    {
        return "blue_notify_{$assignmentUuid}_{$notificationType}";
    }
}
