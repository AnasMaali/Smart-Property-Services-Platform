<?php

namespace Tests\Feature\Admin;

use App\Actions\Notifications\SendEmailNotificationAction;
use App\Support\Notifications\Gateway\EmailDispatchResult;
use App\Support\Notifications\Gateway\EmailNotificationGateway;
use App\Support\Notifications\Gateway\FakeEmailNotificationGateway;
use App\Support\Notifications\OutboundNotificationStatuses;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\Feature\Technician\Concerns\CreatesTechnicianFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B22 - Technician + Customer email notifications, exercised
 * through the existing Admin assign/reassign endpoints. Mirrors
 * tests/Feature/Admin/TechnicianNotificationTest.php's structure for the
 * EMAIL channel - never re-tests eligibility/specialization/double-booking,
 * or the WhatsApp channel itself (already covered there and left
 * untouched by this phase).
 */
class EmailNotificationTest extends TestCase
{
    use CreatesAdminFixtures;
    use CreatesTechnicianFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    private function fakeEmailGateway(): FakeEmailNotificationGateway
    {
        return app(EmailNotificationGateway::class);
    }

    private function assignUrl(object $item): string
    {
        return '/api/v1/admin/booking-items/'.UuidBinary::toString($item->id).'/assign-technician';
    }

    private function reassignUrl(object $item): string
    {
        return '/api/v1/admin/booking-items/'.UuidBinary::toString($item->id).'/reassign-technician';
    }

    private function retryUrl(string $notificationUuid): string
    {
        return '/api/v1/admin/outbound-notifications/'.$notificationUuid.'/retry';
    }

    private function emailNotificationRow(string $assignmentUuid, string $type): ?object
    {
        return DB::table('outbound_notifications')
            ->where('technician_assignment_id', UuidBinary::toBinary($assignmentUuid))
            ->where('channel', 'EMAIL')
            ->where('notification_type', $type)
            ->first();
    }

    private function notificationStatus(object $row): string
    {
        return OutboundNotificationStatuses::code((int) $row->status_id);
    }

    // -----------------------------------------------------------------
    // 1 & 2. Assign -> exactly one technician email + one customer email.
    // -----------------------------------------------------------------

    public function test_assigning_a_technician_creates_exactly_one_technician_and_one_customer_email(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(201);
        $assignmentUuid = $response->json('data.assignment.uuid');

        $this->assertSame(
            1,
            DB::table('outbound_notifications')
                ->where('technician_assignment_id', UuidBinary::toBinary($assignmentUuid))
                ->where('notification_type', 'TECHNICIAN_NEW_ASSIGNMENT_EMAIL')
                ->count()
        );

        $technicianEmail = $this->emailNotificationRow($assignmentUuid, 'TECHNICIAN_NEW_ASSIGNMENT_EMAIL');
        $this->assertNotNull($technicianEmail);
        $this->assertSame('SUBMITTED', $this->notificationStatus($technicianEmail));
        $this->assertSame('EMAIL', $technicianEmail->channel);
        $this->assertSame('TECHNICIAN', $technicianEmail->recipient_type);

        $customerEmail = $this->emailNotificationRow($assignmentUuid, 'CUSTOMER_TECHNICIAN_ASSIGNED_EMAIL');
        $this->assertNotNull($customerEmail);
        $this->assertSame(1, DB::table('outbound_notifications')->where('id', $customerEmail->id)->count());
        $this->assertSame('SUBMITTED', $this->notificationStatus($customerEmail));
        $this->assertSame('CUSTOMER', $customerEmail->recipient_type);

        // Two email sends (technician + customer) plus the WhatsApp one
        // already proven elsewhere - here we only assert the email gateway
        // saw exactly two calls.
        $this->assertCount(2, $this->fakeEmailGateway()->sendCalls);
    }

    // -----------------------------------------------------------------
    // 3. Reassign -> old technician removal email, new technician
    //    assignment email, customer technician-changed email.
    // -----------------------------------------------------------------

    public function test_reassigning_creates_removal_new_assignment_and_customer_changed_emails(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $original = $this->createEligibleTechnician($fixture['specialization_id']);
        $replacement = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $original['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        $originalAssignment = $this->activeAssignmentForItem($fixture['item']);

        $response = $this->postJson($this->reassignUrl($fixture['item']), [
            'technician_uuid' => $replacement['uuid'],
            'release_reason' => 'Original technician unavailable.',
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $newAssignmentUuid = $response->json('data.assignment.uuid');

        $newAssignmentEmail = $this->emailNotificationRow($newAssignmentUuid, 'TECHNICIAN_NEW_ASSIGNMENT_EMAIL');
        $this->assertNotNull($newAssignmentEmail);
        $this->assertSame(UuidBinary::toBinary($replacement['uuid']), $newAssignmentEmail->recipient_id);

        $removedEmail = $this->emailNotificationRow(UuidBinary::toString($originalAssignment->id), 'TECHNICIAN_ASSIGNMENT_REMOVED_EMAIL');
        $this->assertNotNull($removedEmail);
        $this->assertSame(UuidBinary::toBinary($original['uuid']), $removedEmail->recipient_id);

        // The released Technician's payload never names the replacement.
        $removedPayload = json_decode((string) $removedEmail->payload_snapshot, true);
        $this->assertArrayNotHasKey('technician_name', $removedPayload);
        $this->assertStringNotContainsString($replacement['uuid'], (string) $removedEmail->payload_snapshot);

        $customerChangedEmail = $this->emailNotificationRow($newAssignmentUuid, 'CUSTOMER_TECHNICIAN_CHANGED_EMAIL');
        $this->assertNotNull($customerChangedEmail);
        $customerPayload = json_decode((string) $customerChangedEmail->payload_snapshot, true);

        // The new technician's name (not the released one's) is what the
        // customer is told.
        $newTechnicianRow = DB::table('technicians')->where('id', UuidBinary::toBinary($replacement['uuid']))->first(['full_name']);
        $this->assertSame($newTechnicianRow->full_name, $customerPayload['technician_name']);
    }

    // -----------------------------------------------------------------
    // 4. Assignment replay -> no duplicate emails.
    // -----------------------------------------------------------------

    public function test_idempotent_assign_replay_does_not_create_duplicate_emails(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        $assignment = $this->activeAssignmentForItem($fixture['item']);
        // One TECHNICIAN_NEW_ASSIGNMENT_EMAIL + one CUSTOMER_TECHNICIAN_ASSIGNED_EMAIL
        // obligation, both tied to this same assignment.
        $emailCountAfterFirst = DB::table('outbound_notifications')
            ->where('technician_assignment_id', $assignment->id)
            ->where('channel', 'EMAIL')
            ->count();
        $this->assertSame(2, $emailCountAfterFirst);
        $callsAfterFirst = count($this->fakeEmailGateway()->sendCalls);

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $this->assertSame(
            $emailCountAfterFirst,
            DB::table('outbound_notifications')
                ->where('technician_assignment_id', $assignment->id)
                ->where('channel', 'EMAIL')
                ->count()
        );
        $this->assertCount($callsAfterFirst, $this->fakeEmailGateway()->sendCalls);
    }

    // -----------------------------------------------------------------
    // 5. SMTP/send failure -> assignment succeeds, notification stays
    //    PENDING and retryable.
    // -----------------------------------------------------------------

    public function test_send_failure_leaves_notification_pending_and_retryable(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->fakeEmailGateway()->queueNextResult(EmailDispatchResult::failed('SMTP_CONNECTION_FAILED', 'simulated SMTP failure'));

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(201);
        $assignmentUuid = $response->json('data.assignment.uuid');

        $notification = $this->emailNotificationRow($assignmentUuid, 'TECHNICIAN_NEW_ASSIGNMENT_EMAIL');
        $this->assertSame('PENDING', $this->notificationStatus($notification));
        $this->assertSame(1, (int) $notification->attempt_count);
        $this->assertNotNull($notification->next_attempt_at);

        app(SendEmailNotificationAction::class)->handle(UuidBinary::toString($notification->id));

        $resolved = DB::table('outbound_notifications')->where('id', $notification->id)->first();
        $this->assertSame('SUBMITTED', $this->notificationStatus($resolved));

        // Three total gateway calls: the technician send (failed) + the
        // customer send (unrelated, submitted) during assign, then this
        // one retry - filter down to just the two calls for THIS
        // obligation to compare idempotency keys.
        $this->assertCount(3, $this->fakeEmailGateway()->sendCalls);
        $technicianCalls = array_values(array_filter(
            $this->fakeEmailGateway()->sendCalls,
            fn ($call) => $call->notificationUuid === UuidBinary::toString($notification->id)
        ));
        $this->assertCount(2, $technicianCalls);
        $this->assertSame($technicianCalls[0]->providerIdempotencyKey, $technicianCalls[1]->providerIdempotencyKey);
    }

    // -----------------------------------------------------------------
    // 6. Invalid Technician email -> assignment succeeds, no external send.
    // -----------------------------------------------------------------

    public function test_invalid_technician_email_fails_notification_without_calling_gateway(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id'], [
            'email' => 'not-an-email',
        ]);

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(201);
        $notification = $this->emailNotificationRow($response->json('data.assignment.uuid'), 'TECHNICIAN_NEW_ASSIGNMENT_EMAIL');

        $this->assertNotNull($notification);
        $this->assertSame('FAILED', $this->notificationStatus($notification));
        $this->assertSame('INVALID_EMAIL_FORMAT', $notification->last_error_code);

        // The gateway is still called once, for the unrelated (valid)
        // customer email - but never for this Technician's invalid one.
        $this->assertCount(1, $this->fakeEmailGateway()->sendCalls);
        $this->assertNotSame(
            UuidBinary::toString($notification->id),
            $this->fakeEmailGateway()->sendCalls[0]->notificationUuid,
        );
    }

    // -----------------------------------------------------------------
    // 7. Invalid Customer email -> assignment succeeds, no external send.
    // -----------------------------------------------------------------

    public function test_invalid_customer_email_fails_notification_without_calling_gateway(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        // The fixture's own customer row - corrupt its stored email to an
        // invalid format directly (bypassing normal signup validation), to
        // exercise the "malformed address already on file" path per BLUE
        // V1 email spec section 10.
        $customerUserId = DB::table('carts')->where('id', $fixture['booking']->cart_id)->value('customer_user_id');
        DB::table('users')->where('id', $customerUserId)->update(['email' => 'not-an-email']);

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(201);
        $notification = $this->emailNotificationRow($response->json('data.assignment.uuid'), 'CUSTOMER_TECHNICIAN_ASSIGNED_EMAIL');

        $this->assertNotNull($notification);
        $this->assertSame('FAILED', $this->notificationStatus($notification));
        $this->assertSame('INVALID_EMAIL_FORMAT', $notification->last_error_code);
    }

    // -----------------------------------------------------------------
    // 8. Stale Technician assignment email -> SKIPPED.
    // -----------------------------------------------------------------

    public function test_stale_technician_new_assignment_email_is_skipped_not_sent(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $original = $this->createEligibleTechnician($fixture['specialization_id']);
        $replacement = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->fakeEmailGateway()->queueNextResult(EmailDispatchResult::failed('SMTP_TIMEOUT', 'simulated timeout'));

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $original['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        $staleNotification = $this->emailNotificationRow(
            UuidBinary::toString($this->activeAssignmentForItem($fixture['item'])->id),
            'TECHNICIAN_NEW_ASSIGNMENT_EMAIL'
        );
        $this->assertSame('PENDING', $this->notificationStatus($staleNotification));

        $this->postJson($this->reassignUrl($fixture['item']), [
            'technician_uuid' => $replacement['uuid'],
            'release_reason' => 'Reassigned before the first email attempt resolved.',
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $callsBeforeRetry = count($this->fakeEmailGateway()->sendCalls);

        app(SendEmailNotificationAction::class)->handle(UuidBinary::toString($staleNotification->id));

        $resolved = DB::table('outbound_notifications')->where('id', $staleNotification->id)->first();
        $this->assertSame('SKIPPED', $this->notificationStatus($resolved));
        $this->assertCount($callsBeforeRetry, $this->fakeEmailGateway()->sendCalls);
    }

    // -----------------------------------------------------------------
    // 9 & 10. Customer email paid amount uses the historical Booking/
    //         payment snapshot, unaffected by a later Service price change.
    // -----------------------------------------------------------------

    public function test_customer_email_paid_amount_uses_historical_payment_and_ignores_later_price_change(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $payment = DB::table('payment_attempts')->where('id', $fixture['booking']->payment_attempt_id)->first(['confirmed_amount', 'requested_amount']);
        $expectedAmount = (string) ($payment->confirmed_amount ?? $payment->requested_amount);

        // Simulate the Service's live price changing AFTER the Booking was
        // already paid for - the historical Booking must remain immutable.
        DB::table('pricing_rules')
            ->where('pricing_scheme_version_id', $fixture['item']->pricing_scheme_version_id)
            ->update(['effect_amount' => '999999.000000']);

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $notification = $this->emailNotificationRow($response->json('data.assignment.uuid'), 'CUSTOMER_TECHNICIAN_ASSIGNED_EMAIL');
        $payload = json_decode((string) $notification->payload_snapshot, true);

        $this->assertSame($expectedAmount, $payload['paid_amount']);
        $this->assertSame('AED', $payload['currency']);
        $this->assertNotEquals('999999.000000', $payload['paid_amount']);
    }

    // -----------------------------------------------------------------
    // 11. Email content contains no Stripe/payment-provider/refund/
    //     internal pricing internals.
    // -----------------------------------------------------------------

    public function test_email_payloads_contain_no_financial_or_internal_data(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $assignmentUuid = $response->json('data.assignment.uuid');
        $forbidden = ['stripe', 'payment_intent', 'confirmed_amount', 'requested_amount', 'refund', 'pricing_breakdown', 'admin_note'];

        foreach (['TECHNICIAN_NEW_ASSIGNMENT_EMAIL', 'CUSTOMER_TECHNICIAN_ASSIGNED_EMAIL'] as $type) {
            $payload = strtolower((string) $this->emailNotificationRow($assignmentUuid, $type)->payload_snapshot);

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $payload);
            }
        }
    }

    // -----------------------------------------------------------------
    // 12. Human-facing appointment time uses Asia/Dubai.
    // -----------------------------------------------------------------

    public function test_customer_email_uses_asia_dubai_for_human_facing_appointment_time(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        // 10:00 UTC = 14:00 Asia/Dubai (UTC+4).
        $fixture = $this->bookingWithAssignableItem([
            'slot' => ['starts_at' => Carbon::parse('2026-09-15 10:00:00', 'UTC')],
        ]);
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $notification = $this->emailNotificationRow($response->json('data.assignment.uuid'), 'CUSTOMER_TECHNICIAN_ASSIGNED_EMAIL');
        $payload = json_decode((string) $notification->payload_snapshot, true);

        $this->assertSame('2:00 PM', $payload['appointment_start_time']);
    }

    // -----------------------------------------------------------------
    // 13. Retry reuses the SAME obligation, never duplicates.
    // -----------------------------------------------------------------

    public function test_manual_retry_reuses_the_same_email_obligation_never_duplicates(): void
    {
        config(['email_notifications.max_attempts' => 1]);

        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->fakeEmailGateway()->queueNextResult(EmailDispatchResult::failed('SMTP_REJECTED', 'Rejected by provider.'));

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $notification = $this->emailNotificationRow($response->json('data.assignment.uuid'), 'TECHNICIAN_NEW_ASSIGNMENT_EMAIL');
        $this->assertSame('FAILED', $this->notificationStatus($notification));

        $retryResponse = $this->postJson(
            $this->retryUrl(UuidBinary::toString($notification->id)),
            [],
            $this->bearer($admin['access_token'])
        );

        $retryResponse->assertStatus(200);
        $this->assertSame('SUBMITTED', $retryResponse->json('data.notification.status'));

        $this->assertSame(
            1,
            DB::table('outbound_notifications')->where('technician_assignment_id', $notification->technician_assignment_id)->where('channel', 'EMAIL')->where('notification_type', 'TECHNICIAN_NEW_ASSIGNMENT_EMAIL')->count()
        );
    }

    // -----------------------------------------------------------------
    // Admin visibility - the new email status fields appear alongside the
    // existing whatsapp_notification field, with safe fields only.
    // -----------------------------------------------------------------

    public function test_admin_booking_detail_surfaces_both_email_notifications_with_safe_fields_only(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        $response = $this->getJson(
            '/api/v1/admin/bookings/'.UuidBinary::toString($fixture['booking']->id),
            $this->bearer($admin['access_token'])
        );

        $response->assertStatus(200);
        $technicianEmail = $response->json('data.booking.items.0.active_assignment.technician_email_notification');
        $customerEmail = $response->json('data.booking.items.0.active_assignment.customer_email_notification');

        $this->assertNotNull($technicianEmail);
        $this->assertSame('SUBMITTED', $technicianEmail['status']);
        $this->assertNotNull($customerEmail);
        $this->assertSame('SUBMITTED', $customerEmail['status']);

        $json = $response->getContent();
        $this->assertStringNotContainsString('idempotency_key', $json);
        $this->assertStringNotContainsString('payload_snapshot', $json);
    }
}
