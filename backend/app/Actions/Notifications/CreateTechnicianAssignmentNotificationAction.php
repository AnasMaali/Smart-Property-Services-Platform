<?php

namespace App\Actions\Notifications;

use App\Support\Notifications\OutboundNotificationStatuses;
use App\Support\Notifications\TechnicianJobNotificationContent;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * BLUE V1 Phase B21 - writes exactly one durable `outbound_notifications`
 * obligation for a Technician-assignment event, INSIDE the same DB
 * transaction as the assignment/reassignment itself (called from the
 * `$afterMutation` callback App\Actions\Technician\
 * AssignTechnicianToBookingItemAction::assign()/reassign() already expose
 * - see App\Actions\Admin\Technician\AdminAssignTechnicianAction /
 * AdminReassignTechnicianAction). Never calls WhatsApp/Meta, never
 * changes `technician_assignments` - purely a durable-obligation writer,
 * exactly mirroring how App\Actions\Booking\CancelBookingAction persists a
 * `booking_refunds` PENDING row before ever attempting Stripe.
 *
 * Both methods are idempotent: a UNIQUE(idempotency_key) collision means
 * the obligation already exists (e.g. a caller that somehow ran twice for
 * the same real event, which the domain Action's own idempotent-replay
 * guard should already prevent) - never a second row, never an error
 * surfaced to the caller.
 */
final class CreateTechnicianAssignmentNotificationAction
{
    /**
     * @return string The notification uuid (existing or newly created).
     */
    public function createForNewAssignment(string $assignmentUuid): string
    {
        $idempotencyKey = self::idempotencyKey($assignmentUuid, 'TECHNICIAN_NEW_ASSIGNMENT');

        $existing = DB::table('outbound_notifications')->where('idempotency_key', $idempotencyKey)->value('id');

        if ($existing !== null) {
            return UuidBinary::toString($existing);
        }

        $assignmentIdBinary = UuidBinary::toBinary($assignmentUuid);
        $assignment = DB::table('technician_assignments')->where('id', $assignmentIdBinary)->first(['booking_item_id', 'technician_id']);

        $technicianPhone = DB::table('technicians')->where('id', $assignment->technician_id)->value('phone_number');

        $item = DB::table('booking_items')->where('id', $assignment->booking_item_id)->first(['booking_id']);

        $fields = TechnicianJobNotificationContent::forNewAssignment(
            $assignment->booking_item_id,
            $assignment->technician_id
        );

        return $this->insert(
            notificationType: 'TECHNICIAN_NEW_ASSIGNMENT',
            recipientIdBinary: $assignment->technician_id,
            recipientAddress: (string) $technicianPhone,
            bookingIdBinary: $item->booking_id,
            bookingItemIdBinary: $assignment->booking_item_id,
            technicianAssignmentIdBinary: $assignmentIdBinary,
            idempotencyKey: $idempotencyKey,
            payloadSnapshot: $fields,
        );
    }

    /**
     * @return string The notification uuid (existing or newly created).
     */
    public function createForAssignmentRemoved(string $releasedAssignmentUuid): string
    {
        $idempotencyKey = self::idempotencyKey($releasedAssignmentUuid, 'TECHNICIAN_ASSIGNMENT_REMOVED');

        $existing = DB::table('outbound_notifications')->where('idempotency_key', $idempotencyKey)->value('id');

        if ($existing !== null) {
            return UuidBinary::toString($existing);
        }

        $assignmentIdBinary = UuidBinary::toBinary($releasedAssignmentUuid);
        $assignment = DB::table('technician_assignments')->where('id', $assignmentIdBinary)->first(['booking_item_id', 'technician_id']);

        $technicianPhone = DB::table('technicians')->where('id', $assignment->technician_id)->value('phone_number');

        $item = DB::table('booking_items')->where('id', $assignment->booking_item_id)->first(['booking_id']);

        $fields = TechnicianJobNotificationContent::forAssignmentRemoved($assignment->booking_item_id);

        return $this->insert(
            notificationType: 'TECHNICIAN_ASSIGNMENT_REMOVED',
            recipientIdBinary: $assignment->technician_id,
            recipientAddress: (string) $technicianPhone,
            bookingIdBinary: $item->booking_id,
            bookingItemIdBinary: $assignment->booking_item_id,
            technicianAssignmentIdBinary: $assignmentIdBinary,
            idempotencyKey: $idempotencyKey,
            payloadSnapshot: $fields,
        );
    }

    /**
     * @param  array<string, string>  $payloadSnapshot
     */
    private function insert(
        string $notificationType,
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
                'channel' => 'WHATSAPP',
                'notification_type' => $notificationType,
                'recipient_type' => 'TECHNICIAN',
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
            $existing = DB::table('outbound_notifications')->where('idempotency_key', $idempotencyKey)->value('id');

            if ($existing !== null) {
                return UuidBinary::toString($existing);
            }

            throw new RuntimeException(
                'Outbound notification insert violated a unique constraint but no resolvable row was found.',
                previous: $exception,
            );
        }

        return $notificationUuid;
    }

    /**
     * Deterministic, persisted per obligation - the SAME assignment
     * uuid + notification type always resolves to the same
     * `outbound_notifications` row, so a retry (queue-style recovery
     * command, duplicate Admin request, worker restart) can never create
     * a second one. Mirrors `booking_refunds.idempotency_key`'s own
     * "blue_refund_{uuid}" convention.
     */
    private static function idempotencyKey(string $assignmentUuid, string $notificationType): string
    {
        return "blue_notify_{$assignmentUuid}_{$notificationType}";
    }
}
