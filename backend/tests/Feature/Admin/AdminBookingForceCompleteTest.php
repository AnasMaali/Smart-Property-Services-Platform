<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B17 - Admin "Force Complete" (App\Actions\Admin\Booking\
 * AdminForceCompleteBookingAction), a break-glass operational recovery
 * override. Normal technician Complete Work is proven unchanged by the
 * existing tests/Feature/Technician/TechnicianJobExecutionTest.php suite
 * (re-run green after this phase's TransitionBooking(Item)StatusAction
 * changes) - not re-tested here.
 */
class AdminBookingForceCompleteTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    /**
     * Mirrors AdminJobExecutionTest::bookingWithTwoAssignableItems() - a
     * single Booking with two independently assignable Items, needed to
     * exercise the "one item started, one item never started" mixed-state
     * rejection.
     *
     * @return array{customer: array, booking: object, items: Collection, specialization_id: int}
     */
    private function bookingWithTwoAssignableItems(): array
    {
        $specializationId = $this->createSpecialization();
        $serviceOne = $this->createPricedCartService();
        $serviceTwo = $this->createPricedCartService();
        $this->linkServiceSpecialization($serviceOne['uuid'], $specializationId);
        $this->linkServiceSpecialization($serviceTwo['uuid'], $specializationId);

        $customer = $this->createAuthenticatedCartCustomer();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $serviceOne['uuid'], 'quantity' => 1])->assertStatus(201);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $serviceTwo['uuid'], 'quantity' => 1])->assertStatus(201);

        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);

        $slot = $this->createAppointmentSlot();
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        $createResponse = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $paymentRow = $this->paymentRow($createResponse->json('data.payment.uuid'));

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $paymentRow->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $paymentRow->requested_amount,
        ]));

        $payment = $this->paymentRow(UuidBinary::toString($paymentRow->id));
        $booking = $this->bookingRowForPayment($payment);
        $items = DB::table('booking_items')->where('booking_id', $booking->id)->orderBy('id')->get();

        return ['customer' => $customer, 'booking' => $booking, 'items' => $items, 'specialization_id' => $specializationId];
    }

    private function startWork(array $admin, object $item, int $specializationId): void
    {
        $technician = $this->createEligibleTechnician($specializationId);
        $itemUuid = UuidBinary::toString($item->id);

        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/assign-technician", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(201);
        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/start-work", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);
    }

    private function forceComplete(string $accessToken, string $bookingUuid, ?string $reason = 'Technician unreachable, customer confirmed completion by phone.'): TestResponse
    {
        return $this->postJson(
            '/api/v1/admin/bookings/'.$bookingUuid.'/force-complete',
            $reason === null ? [] : ['reason' => $reason],
            $this->bearer($accessToken)
        );
    }

    private function denyCapability(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'bookings.force_complete')->value('id');
        DB::table('admin_role_permissions')->where('role_id', $adminRoleId)->where('permission_id', $permissionId)->delete();
    }

    private function auditCount(): int
    {
        return DB::table('admin_audit_logs')->where('action_code', 'BOOKING_FORCE_COMPLETED')->count();
    }

    // -----------------------------------------------------------------
    // Auth / authz / step-up
    // -----------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/v1/admin/bookings/'.UuidBinary::generate().'/force-complete', ['reason' => 'x'])
            ->assertStatus(401);
    }

    public function test_admin_without_capability_is_rejected(): void
    {
        $this->denyCapability();
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->markStepUpVerified($admin['session_uuid']);

        $this->forceComplete($admin['access_token'], UuidBinary::generate())->assertStatus(403);
        $this->assertSame(0, $this->auditCount());
    }

    public function test_capability_without_fresh_step_up_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->forceComplete($admin['access_token'], UuidBinary::generate())
            ->assertStatus(428)
            ->assertJson(['code' => 'STEP_UP_REQUIRED']);

        $this->assertSame(0, $this->auditCount());
    }

    public function test_super_admin_is_allowed_via_the_existing_authorization_override(): void
    {
        $this->denyCapability();
        $admin = $this->createAndLoginAdmin(['SUPER_ADMIN']);
        $this->markStepUpVerified($admin['session_uuid']);
        $fixture = $this->successfulPayment();
        $bookingUuid = UuidBinary::toString($this->bookingRowForPayment($fixture['payment'])->id);

        $this->forceComplete($admin['access_token'], $bookingUuid)->assertStatus(409);
        // PAID Booking with a PENDING_ASSIGNMENT item is correctly rejected
        // (proves authorization passed - a 403/428 would prove otherwise).
    }

    // -----------------------------------------------------------------
    // Mandatory reason
    // -----------------------------------------------------------------

    public function test_reason_is_mandatory(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->markStepUpVerified($admin['session_uuid']);
        $bookingUuid = UuidBinary::generate();

        $this->forceComplete($admin['access_token'], $bookingUuid, null)->assertStatus(422);
        $this->forceComplete($admin['access_token'], $bookingUuid, '  ')->assertStatus(422);
        $this->assertSame(0, $this->auditCount());
    }

    // -----------------------------------------------------------------
    // Eligible coherent force-completion
    // -----------------------------------------------------------------

    public function test_force_completes_an_eligible_booking_coherently_with_history_and_audit(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->markStepUpVerified($admin['session_uuid']);
        $fixture = $this->bookingWithTwoAssignableItems();

        foreach ($fixture['items'] as $item) {
            $this->startWork($admin, $item, $fixture['specialization_id']);
        }

        $bookingUuid = UuidBinary::toString($fixture['booking']->id);
        $paymentBefore = DB::table('payment_attempts')->where('id', DB::table('bookings')->where('id', $fixture['booking']->id)->value('payment_attempt_id'))->first();
        $bookingBefore = DB::table('bookings')->where('id', $fixture['booking']->id)->first();
        $itemsBefore = DB::table('booking_items')->where('booking_id', $fixture['booking']->id)->orderBy('id')->get();

        $response = $this->forceComplete($admin['access_token'], $bookingUuid, 'Customer confirmed job done by phone; technician app crashed.')
            ->assertStatus(200);

        $this->assertSame('COMPLETED', $response->json('data.booking.status'));

        $bookingAfter = DB::table('bookings')->where('id', $fixture['booking']->id)->first();
        $this->assertSame('COMPLETED', DB::table('booking_statuses')->where('id', $bookingAfter->status_id)->value('code'));

        $itemsAfter = DB::table('booking_items')->where('booking_id', $fixture['booking']->id)->orderBy('id')->get();
        foreach ($itemsAfter as $item) {
            $this->assertSame('COMPLETED', DB::table('booking_item_statuses')->where('id', $item->status_id)->value('code'));
        }

        // History: actor + reason preserved at both levels.
        $bookingHistory = DB::table('booking_status_history')->where('booking_id', $fixture['booking']->id)->where('to_status_id', $bookingAfter->status_id)->first();
        $this->assertSame($admin['user_uuid'], UuidBinary::toString($bookingHistory->changed_by_user_id));
        $this->assertSame('Customer confirmed job done by phone; technician app crashed.', $bookingHistory->reason);

        foreach ($itemsAfter as $item) {
            $itemHistory = DB::table('booking_item_status_history')->where('booking_item_id', $item->id)->orderByDesc('changed_at')->first();
            $this->assertSame($admin['user_uuid'], UuidBinary::toString($itemHistory->changed_by_user_id));
            $this->assertSame('Customer confirmed job done by phone; technician app crashed.', $itemHistory->reason);
        }

        // Audit written exactly once.
        $this->assertSame(1, $this->auditCount());
        $audit = DB::table('admin_audit_logs')->where('action_code', 'BOOKING_FORCE_COMPLETED')->first();
        $this->assertSame('BOOKING', $audit->entity_type);
        $this->assertSame($bookingUuid, $audit->entity_identifier);

        // Financial/contract/appointment isolation.
        $paymentAfter = DB::table('payment_attempts')->where('id', $bookingAfter->payment_attempt_id)->first();
        $this->assertSame((string) $paymentBefore->confirmed_amount, (string) $paymentAfter->confirmed_amount);
        $this->assertSame($bookingBefore->appointment_slot_id, $bookingAfter->appointment_slot_id);
        $this->assertSame($bookingBefore->service_contract_id, $bookingAfter->service_contract_id);

        foreach ($itemsBefore as $index => $before) {
            $after = $itemsAfter[$index];
            $this->assertSame((string) $before->base_amount_snapshot, (string) $after->base_amount_snapshot);
            $this->assertSame((string) $before->line_total_amount, (string) $after->line_total_amount);
        }

        // Technician assignments are left exactly as normal Complete Work
        // leaves them - never released.
        $assignments = DB::table('technician_assignments')->whereIn('booking_item_id', $itemsAfter->pluck('id'))->get();
        foreach ($assignments as $assignment) {
            $this->assertNull($assignment->released_at);
        }
    }

    // -----------------------------------------------------------------
    // Terminal-state / unsafe-state rejection
    // -----------------------------------------------------------------

    public function test_cancelled_booking_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->markStepUpVerified($admin['session_uuid']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);
        $bookingUuid = UuidBinary::toString($booking->id);

        $this->postJson('/api/v1/bookings/'.$bookingUuid.'/cancel', [], ['Authorization' => 'Bearer '.$fixture['customer']['access_token']])->assertStatus(200);

        $this->forceComplete($admin['access_token'], $bookingUuid)->assertStatus(409);
        $this->assertSame(0, $this->auditCount());
        $this->assertSame('CANCELLED', DB::table('booking_statuses')->where('id', DB::table('bookings')->where('id', $booking->id)->value('status_id'))->value('code'));
    }

    public function test_mixed_state_with_one_item_not_yet_started_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->markStepUpVerified($admin['session_uuid']);
        $fixture = $this->bookingWithTwoAssignableItems();

        // Only the FIRST item is started; the second stays PENDING_ASSIGNMENT.
        $this->startWork($admin, $fixture['items'][0], $fixture['specialization_id']);

        $itemsBefore = DB::table('booking_items')->where('booking_id', $fixture['booking']->id)->orderBy('id')->get();

        $this->forceComplete($admin['access_token'], UuidBinary::toString($fixture['booking']->id))->assertStatus(409);

        $itemsAfter = DB::table('booking_items')->where('booking_id', $fixture['booking']->id)->orderBy('id')->get();
        $this->assertEquals($itemsBefore, $itemsAfter);
        $this->assertSame(0, $this->auditCount());
        $this->assertNotSame('COMPLETED', DB::table('booking_statuses')->where('id', DB::table('bookings')->where('id', $fixture['booking']->id)->value('status_id'))->value('code'));
    }

    // -----------------------------------------------------------------
    // Replay safety
    // -----------------------------------------------------------------

    public function test_replay_after_completion_is_idempotent_with_no_duplicate_audit(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->markStepUpVerified($admin['session_uuid']);
        $fixture = $this->bookingWithTwoAssignableItems();

        foreach ($fixture['items'] as $item) {
            $this->startWork($admin, $item, $fixture['specialization_id']);
        }

        $bookingUuid = UuidBinary::toString($fixture['booking']->id);
        $this->forceComplete($admin['access_token'], $bookingUuid, 'First force-complete.')->assertStatus(200);
        $this->assertSame(1, $this->auditCount());

        $response = $this->forceComplete($admin['access_token'], $bookingUuid, 'Second attempt.')->assertStatus(200);
        $this->assertSame('Booking is already completed.', $response->json('message'));
        $this->assertSame(1, $this->auditCount());
    }
}
