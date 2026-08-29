<?php

namespace Tests\Feature\Admin;

use App\Actions\Notifications\SendTechnicianNotificationAction;
use App\Actions\Technician\AssignTechnicianToBookingItemAction;
use App\Support\Notifications\Gateway\FakeTechnicianNotificationGateway;
use App\Support\Notifications\Gateway\NotificationDispatchResult;
use App\Support\Notifications\Gateway\TechnicianNotificationGateway;
use App\Support\Notifications\OutboundNotificationStatuses;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\Feature\Technician\Concerns\CreatesTechnicianFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B21 - Technician WhatsApp job notifications, exercised
 * through the existing Admin assign/reassign endpoints. Never re-tests
 * eligibility/specialization/double-booking (see
 * tests/Feature/Admin/AdminAssignmentTest.php) - only the notification
 * obligation this phase adds on top of that already-tested flow.
 */
class TechnicianNotificationTest extends TestCase
{
    use CreatesAdminFixtures;
    use CreatesTechnicianFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    private function fakeNotificationGateway(): FakeTechnicianNotificationGateway
    {
        return app(TechnicianNotificationGateway::class);
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

    private function notificationRow(string $assignmentUuid, string $type): ?object
    {
        return DB::table('outbound_notifications')
            ->where('technician_assignment_id', UuidBinary::toBinary($assignmentUuid))
            ->where('notification_type', $type)
            ->first();
    }

    private function notificationStatus(object $row): string
    {
        return OutboundNotificationStatuses::code((int) $row->status_id);
    }

    // -----------------------------------------------------------------
    // 1. Assign -> exactly one NEW_ASSIGNMENT obligation.
    // -----------------------------------------------------------------

    public function test_assigning_a_technician_creates_exactly_one_new_assignment_notification(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(201);
        $assignmentUuid = $response->json('data.assignment.uuid');

        // Exactly one WHATSAPP obligation - BLUE V1 Phase B22 also creates
        // EMAIL-channel obligations for the same assignment (see
        // tests/Feature/Admin/EmailNotificationTest.php), so this count is
        // now scoped to the WHATSAPP channel specifically.
        $this->assertSame(
            1,
            DB::table('outbound_notifications')->where('technician_assignment_id', UuidBinary::toBinary($assignmentUuid))->where('channel', 'WHATSAPP')->count()
        );

        $notification = $this->notificationRow($assignmentUuid, 'TECHNICIAN_NEW_ASSIGNMENT');
        $this->assertNotNull($notification);
        $this->assertSame('SUBMITTED', $this->notificationStatus($notification));
        $this->assertSame('WHATSAPP', $notification->channel);
        $this->assertSame('TECHNICIAN', $notification->recipient_type);
        $this->assertCount(1, $this->fakeNotificationGateway()->sendCalls);
    }

    public function test_admin_booking_detail_surfaces_the_whatsapp_notification_with_safe_fields_only(): void
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
        $whatsapp = $response->json('data.booking.items.0.active_assignment.whatsapp_notification');

        $this->assertNotNull($whatsapp);
        $this->assertSame('SUBMITTED', $whatsapp['status']);
        $this->assertSame(
            ['uuid', 'status', 'provider_message_reference', 'requested_at', 'submitted_at', 'failed_at', 'failure_code', 'failure_message'],
            array_keys($whatsapp)
        );

        // Safe fields only - never the raw normalized payload, the
        // idempotency key, or the customer/location content it carries.
        $json = $response->getContent();
        $this->assertStringNotContainsString('idempotency_key', $json);
        $this->assertStringNotContainsString('payload_snapshot', $json);
    }

    // -----------------------------------------------------------------
    // 2. Reassign -> new Technician gets NEW_ASSIGNMENT, old Technician
    //    gets ASSIGNMENT_REMOVED.
    // -----------------------------------------------------------------

    public function test_reassigning_creates_new_assignment_and_assignment_removed_notifications(): void
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

        $newNotification = $this->notificationRow($newAssignmentUuid, 'TECHNICIAN_NEW_ASSIGNMENT');
        $this->assertNotNull($newNotification);
        $this->assertSame(UuidBinary::toBinary($replacement['uuid']), $newNotification->recipient_id);

        $removedNotification = $this->notificationRow(UuidBinary::toString($originalAssignment->id), 'TECHNICIAN_ASSIGNMENT_REMOVED');
        $this->assertNotNull($removedNotification);
        $this->assertSame(UuidBinary::toBinary($original['uuid']), $removedNotification->recipient_id);

        // The released Technician's payload never names the replacement.
        $removedPayload = json_decode((string) $removedNotification->payload_snapshot, true);
        $this->assertArrayNotHasKey('technician_name', $removedPayload);
        $this->assertStringNotContainsString($replacement['uuid'], (string) $removedNotification->payload_snapshot);
    }

    // -----------------------------------------------------------------
    // 3. Idempotent replay - reassigning to the SAME already-active
    //    Technician never creates a second obligation.
    // -----------------------------------------------------------------

    public function test_idempotent_reassign_replay_does_not_create_a_duplicate_notification(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        $assignment = $this->activeAssignmentForItem($fixture['item']);
        $callsAfterFirst = count($this->fakeNotificationGateway()->sendCalls);

        // Reassigning to the SAME active Technician resolves ALREADY_ASSIGNED
        // (see AssignTechnicianToBookingItemAction::reassign()) - the
        // $afterMutation hook (and therefore notification creation) never
        // runs for that outcome.
        $this->postJson($this->reassignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
            'release_reason' => 'No change.',
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $this->assertSame(
            1,
            DB::table('outbound_notifications')->where('technician_assignment_id', $assignment->id)->where('channel', 'WHATSAPP')->count()
        );
        $this->assertCount($callsAfterFirst, $this->fakeNotificationGateway()->sendCalls);
    }

    public function test_idempotent_assign_replay_to_the_same_technician_does_not_create_a_duplicate_notification(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        $assignment = $this->activeAssignmentForItem($fixture['item']);
        $callsAfterFirst = count($this->fakeNotificationGateway()->sendCalls);

        // A second assign() call for the SAME already-active Technician
        // resolves ALREADY_ASSIGNED (see AssignTechnicianToBookingItemAction::
        // assign()) - the $afterMutation hook never runs for that outcome.
        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $this->assertSame(
            1,
            DB::table('outbound_notifications')->where('technician_assignment_id', $assignment->id)->where('channel', 'WHATSAPP')->count()
        );
        $this->assertCount($callsAfterFirst, $this->fakeNotificationGateway()->sendCalls);
    }

    /**
     * Proves the transactional-outbox invariant itself (BLUE V1 WhatsApp
     * spec section 4/11): a Technician assignment and its notification
     * obligation are written in ONE transaction, so a failure while
     * writing the obligation must roll back the assignment too - never a
     * partially-committed state. Exercises
     * App\Actions\Technician\AssignTechnicianToBookingItemAction directly
     * (the exact mechanism App\Actions\Admin\Technician\
     * AdminAssignTechnicianAction's $afterMutation hook relies on) rather
     * than reaching into App\Actions\Notifications\
     * CreateTechnicianAssignmentNotificationAction, which - like every
     * other Action in this codebase - is `final` and not meant to be
     * substituted/subclassed by a test.
     */
    public function test_assignment_transaction_rolls_back_if_the_after_mutation_hook_throws(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $adminUuid = $this->createAdminUser();

        try {
            app(AssignTechnicianToBookingItemAction::class)->assign(
                UuidBinary::toString($fixture['item']->id),
                $technician['uuid'],
                $adminUuid,
                null,
                function (): void {
                    throw new RuntimeException('Simulated notification-obligation write failure.');
                }
            );
            $this->fail('Expected the simulated exception to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated notification-obligation write failure.', $e->getMessage());
        }

        // Nothing committed: no assignment row, item status unchanged.
        $this->assertNull($this->activeAssignmentForItem($fixture['item']));
        $freshItem = DB::table('booking_items')->where('id', $fixture['item']->id)->first();
        $this->assertSame(
            'PENDING_ASSIGNMENT',
            DB::table('booking_item_statuses')->where('id', $freshItem->status_id)->value('code')
        );
        $this->assertSame(0, DB::table('outbound_notifications')->count());
    }

    // -----------------------------------------------------------------
    // 5. Transient provider failure -> assignment succeeds, notification
    //    stays PENDING and retryable.
    // -----------------------------------------------------------------

    public function test_transient_provider_failure_leaves_notification_pending_and_retryable(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->fakeNotificationGateway()->queueNextResult(NotificationDispatchResult::unknown('simulated timeout'));

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(201);
        $assignmentUuid = $response->json('data.assignment.uuid');

        $notification = $this->notificationRow($assignmentUuid, 'TECHNICIAN_NEW_ASSIGNMENT');
        $this->assertSame('PENDING', $this->notificationStatus($notification));
        $this->assertSame(1, (int) $notification->attempt_count);
        $this->assertNotNull($notification->next_attempt_at);

        // A later retry (recovery command / manual) with no queued failure
        // resolves it, using the SAME idempotency key.
        app(SendTechnicianNotificationAction::class)->handle(UuidBinary::toString($notification->id));

        $resolved = DB::table('outbound_notifications')->where('id', $notification->id)->first();
        $this->assertSame('SUBMITTED', $this->notificationStatus($resolved));
        $this->assertCount(2, $this->fakeNotificationGateway()->sendCalls);
        $this->assertSame(
            $this->fakeNotificationGateway()->sendCalls[0]->providerIdempotencyKey,
            $this->fakeNotificationGateway()->sendCalls[1]->providerIdempotencyKey,
        );
    }

    // -----------------------------------------------------------------
    // 6. Permanent provider failure -> assignment succeeds, notification
    //    terminal FAILED, never auto-retried.
    // -----------------------------------------------------------------

    public function test_definitive_provider_failure_marks_notification_failed(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->fakeNotificationGateway()->queueNextResult(
            NotificationDispatchResult::definitiveFailure('TEMPLATE_NOT_APPROVED', 'The template is not yet approved.')
        );

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(201);
        $notification = $this->notificationRow($response->json('data.assignment.uuid'), 'TECHNICIAN_NEW_ASSIGNMENT');

        $this->assertSame('FAILED', $this->notificationStatus($notification));
        $this->assertSame('TEMPLATE_NOT_APPROVED', $notification->last_error_code);
        $this->assertNotNull($notification->failed_at);

        // The recovery command's own query only ever selects PENDING rows.
        $this->assertSame(
            0,
            DB::table('outbound_notifications')->where('status_id', OutboundNotificationStatuses::id('PENDING'))->count()
        );
    }

    // -----------------------------------------------------------------
    // Ambiguous/lost-response provider outcome - Meta's Cloud API has no
    // request-level idempotency key, so this can NEVER be treated as
    // ordinary-retryable UNKNOWN: a blind resend could create a second
    // real WhatsApp message. It must become the terminal
    // RECONCILIATION_REQUIRED state on the FIRST such outcome, be
    // excluded from both the recovery command and the ordinary Admin
    // retry endpoint, and be clearly distinguishable from FAILED/PENDING.
    // -----------------------------------------------------------------

    public function test_ambiguous_provider_outcome_never_triggers_an_automatic_resend(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->fakeNotificationGateway()->queueNextResult(
            NotificationDispatchResult::ambiguous('simulated connection timeout')
        );

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(201);
        $notification = $this->notificationRow($response->json('data.assignment.uuid'), 'TECHNICIAN_NEW_ASSIGNMENT');

        // Distinguishable from both ordinary FAILED and PENDING.
        $this->assertSame('RECONCILIATION_REQUIRED', $this->notificationStatus($notification));
        $this->assertNotSame('FAILED', $this->notificationStatus($notification));
        $this->assertNotSame('PENDING', $this->notificationStatus($notification));
        $this->assertSame('PROVIDER_RESPONSE_UNCONFIRMED', $notification->last_error_code);
        $this->assertNotNull($notification->failed_at);
        $this->assertNull($notification->next_attempt_at);

        // Exactly one send call was ever made - the ambiguous outcome
        // never caused (and must never cause) an automatic second one.
        $this->assertCount(1, $this->fakeNotificationGateway()->sendCalls);

        // The assignment itself remains valid regardless of the
        // notification outcome.
        $this->assertSame('ASSIGNED', DB::table('booking_item_statuses')->where(
            'id',
            DB::table('booking_items')->where('id', $fixture['item']->id)->value('status_id')
        )->value('code'));
    }

    public function test_recovery_command_excludes_the_ambiguous_reconciliation_required_state(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->fakeNotificationGateway()->queueNextResult(
            NotificationDispatchResult::ambiguous('simulated connection timeout')
        );

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $notification = $this->notificationRow($response->json('data.assignment.uuid'), 'TECHNICIAN_NEW_ASSIGNMENT');
        $this->assertSame('RECONCILIATION_REQUIRED', $this->notificationStatus($notification));
        $callsBefore = count($this->fakeNotificationGateway()->sendCalls);

        $this->artisan('notifications:send-pending')->assertSuccessful();

        $this->assertCount($callsBefore, $this->fakeNotificationGateway()->sendCalls);
        $this->assertSame('RECONCILIATION_REQUIRED', $this->notificationStatus(
            DB::table('outbound_notifications')->where('id', $notification->id)->first()
        ));
    }

    public function test_direct_send_action_never_resends_an_ambiguous_notification(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->fakeNotificationGateway()->queueNextResult(
            NotificationDispatchResult::ambiguous('simulated connection timeout')
        );

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $notification = $this->notificationRow($response->json('data.assignment.uuid'), 'TECHNICIAN_NEW_ASSIGNMENT');
        $callsBefore = count($this->fakeNotificationGateway()->sendCalls);

        // A direct call to the send Action (e.g. a stray automated
        // process) is a safe no-op once the row is no longer PENDING -
        // the SAME PENDING-only guard that protects SUBMITTED/FAILED
        // also protects RECONCILIATION_REQUIRED.
        app(SendTechnicianNotificationAction::class)->handle(UuidBinary::toString($notification->id));

        $this->assertCount($callsBefore, $this->fakeNotificationGateway()->sendCalls);
        $this->assertSame('RECONCILIATION_REQUIRED', $this->notificationStatus(
            DB::table('outbound_notifications')->where('id', $notification->id)->first()
        ));
    }

    public function test_admin_cannot_retry_a_reconciliation_required_notification(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->fakeNotificationGateway()->queueNextResult(
            NotificationDispatchResult::ambiguous('simulated connection timeout')
        );

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $notification = $this->notificationRow($response->json('data.assignment.uuid'), 'TECHNICIAN_NEW_ASSIGNMENT');
        $callsBefore = count($this->fakeNotificationGateway()->sendCalls);

        $this->postJson($this->retryUrl(UuidBinary::toString($notification->id)), [], $this->bearer($admin['access_token']))
            ->assertStatus(409);

        $this->assertCount($callsBefore, $this->fakeNotificationGateway()->sendCalls);
        $this->assertSame('RECONCILIATION_REQUIRED', $this->notificationStatus(
            DB::table('outbound_notifications')->where('id', $notification->id)->first()
        ));
    }

    // -----------------------------------------------------------------
    // 7. Invalid Technician phone -> assignment succeeds, notification
    //    FAILED safely, gateway never called.
    // -----------------------------------------------------------------

    public function test_invalid_technician_phone_fails_notification_without_calling_gateway(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id'], [
            'phone_number' => '0501234567',
        ]);

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(201);
        $notification = $this->notificationRow($response->json('data.assignment.uuid'), 'TECHNICIAN_NEW_ASSIGNMENT');

        $this->assertSame('FAILED', $this->notificationStatus($notification));
        $this->assertSame('INVALID_PHONE_FORMAT', $notification->last_error_code);
        $this->assertCount(0, $this->fakeNotificationGateway()->sendCalls);
    }

    // -----------------------------------------------------------------
    // 8. A stale queued NEW_ASSIGNMENT, retried AFTER its assignment was
    //    released by a reassignment, is SKIPPED - never sent.
    // -----------------------------------------------------------------

    public function test_stale_new_assignment_notification_is_skipped_not_sent(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $original = $this->createEligibleTechnician($fixture['specialization_id']);
        $replacement = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->fakeNotificationGateway()->queueNextResult(NotificationDispatchResult::unknown('simulated timeout'));

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $original['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        $staleNotification = $this->notificationRow(
            UuidBinary::toString($this->activeAssignmentForItem($fixture['item'])->id),
            'TECHNICIAN_NEW_ASSIGNMENT'
        );
        $this->assertSame('PENDING', $this->notificationStatus($staleNotification));

        // Reassign away from the original Technician before the stale
        // obligation above is ever retried.
        $this->postJson($this->reassignUrl($fixture['item']), [
            'technician_uuid' => $replacement['uuid'],
            'release_reason' => 'Reassigned before the first WhatsApp attempt resolved.',
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $callsBeforeRetry = count($this->fakeNotificationGateway()->sendCalls);

        app(SendTechnicianNotificationAction::class)->handle(UuidBinary::toString($staleNotification->id));

        $resolved = DB::table('outbound_notifications')->where('id', $staleNotification->id)->first();
        $this->assertSame('SKIPPED', $this->notificationStatus($resolved));
        // No new gateway call was made for the stale retry.
        $this->assertCount($callsBeforeRetry, $this->fakeNotificationGateway()->sendCalls);
    }

    // -----------------------------------------------------------------
    // 10. Sensitive-data isolation.
    // -----------------------------------------------------------------

    public function test_notification_payload_contains_no_financial_or_internal_data(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $notification = $this->notificationRow($response->json('data.assignment.uuid'), 'TECHNICIAN_NEW_ASSIGNMENT');
        $payload = strtolower((string) $notification->payload_snapshot);

        foreach (['stripe', 'payment_intent', 'confirmed_amount', 'requested_amount', 'refund', 'pricing_breakdown', 'admin_note'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }

        $sentCall = $this->fakeNotificationGateway()->sendCalls[0];
        $this->assertStringNotContainsString('stripe', strtolower($sentCall->renderedText));
    }

    // -----------------------------------------------------------------
    // 11. Human-facing appointment date/time uses Asia/Dubai.
    // -----------------------------------------------------------------

    public function test_notification_uses_asia_dubai_for_human_facing_appointment_time(): void
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

        $notification = $this->notificationRow($response->json('data.assignment.uuid'), 'TECHNICIAN_NEW_ASSIGNMENT');
        $payload = json_decode((string) $notification->payload_snapshot, true);

        $this->assertSame('2:00 PM', $payload['appointment_start_time']);
    }

    // -----------------------------------------------------------------
    // 12. Recovery command only retries PENDING obligations due for
    //     retry - never SUBMITTED/FAILED/SKIPPED, never before backoff.
    // -----------------------------------------------------------------

    public function test_recovery_command_only_retries_pending_notifications_due_for_retry(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->fakeNotificationGateway()->queueNextResult(NotificationDispatchResult::unknown('simulated timeout'));

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $notification = $this->notificationRow($response->json('data.assignment.uuid'), 'TECHNICIAN_NEW_ASSIGNMENT');
        $this->assertSame('PENDING', $this->notificationStatus($notification));
        $callsBefore = count($this->fakeNotificationGateway()->sendCalls);

        // Still within the backoff window - the command must not retry yet.
        $this->artisan('notifications:send-pending')->assertSuccessful();
        $this->assertCount($callsBefore, $this->fakeNotificationGateway()->sendCalls);
        $this->assertSame('PENDING', $this->notificationStatus(
            DB::table('outbound_notifications')->where('id', $notification->id)->first()
        ));

        // Fast-forward past the backoff window - now it is due.
        Carbon::setTestNow(now()->addMinutes(10));
        $this->artisan('notifications:send-pending')->assertSuccessful();
        Carbon::setTestNow();

        $this->assertSame('SUBMITTED', $this->notificationStatus(
            DB::table('outbound_notifications')->where('id', $notification->id)->first()
        ));
    }

    // -----------------------------------------------------------------
    // 13. Manual retry reuses the SAME obligation, never duplicates.
    // -----------------------------------------------------------------

    public function test_manual_retry_reuses_the_same_obligation_never_duplicates(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->fakeNotificationGateway()->queueNextResult(
            NotificationDispatchResult::definitiveFailure('TEMPLATE_NOT_APPROVED', 'Not approved yet.')
        );

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $notification = $this->notificationRow($response->json('data.assignment.uuid'), 'TECHNICIAN_NEW_ASSIGNMENT');
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
            DB::table('outbound_notifications')->where('technician_assignment_id', $notification->technician_assignment_id)->where('channel', 'WHATSAPP')->count()
        );

        // Retrying an already-SUBMITTED notification is rejected, not a
        // silent no-op that could be mistaken for a second send.
        $this->postJson($this->retryUrl(UuidBinary::toString($notification->id)), [], $this->bearer($admin['access_token']))
            ->assertStatus(409);
    }

    public function test_manual_retry_rejects_unknown_or_malformed_notification_uuid(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->postJson($this->retryUrl('not-a-uuid'), [], $this->bearer($admin['access_token']))
            ->assertStatus(404);

        $this->postJson($this->retryUrl((string) UuidBinary::generate()), [], $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    public function test_manual_retry_requires_admin_authentication(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->postJson($this->assignUrl(
            $fixture['item']
        ), ['technician_uuid' => $technician['uuid']], $this->bearer($this->createAndLoginAdmin(['ADMIN'])['access_token']))
            ->assertStatus(201);

        $notification = $this->notificationRow(
            UuidBinary::toString($this->activeAssignmentForItem($fixture['item'])->id),
            'TECHNICIAN_NEW_ASSIGNMENT'
        );

        // No Authorization header at all - the same `technicians.assign`
        // capability middleware every other assign/reassign route already
        // requires (see AdminAssignmentTest::test_customer_cannot_assign_a_technician
        // for the equivalent, already-proven middleware behavior).
        $this->postJson($this->retryUrl(UuidBinary::toString($notification->id)))->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // 9. Log driver - proven at the unit level
    //    (tests/Unit/Notifications/TechnicianJobNotificationContentTest.php);
    //    this integration suite always runs under the Fake gateway (see
    //    App\Providers\TechnicianNotificationServiceProvider), matching
    //    how every other provider-backed feature test in this codebase
    //    proves the LOG driver's content shape at the unit level instead
    //    of re-registering a second gateway binding mid-suite.
    // -----------------------------------------------------------------
}
