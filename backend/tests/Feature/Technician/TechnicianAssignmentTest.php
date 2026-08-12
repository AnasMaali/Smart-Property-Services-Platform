<?php

namespace Tests\Feature\Technician;

use App\Actions\Booking\TransitionBookingItemStatusAction;
use App\Actions\Technician\AssignTechnicianToBookingItemAction;
use App\Support\Technician\TechnicianAssignmentOutcome;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Technician\Concerns\CreatesTechnicianFixtures;
use Tests\TestCase;

class TechnicianAssignmentTest extends TestCase
{
    use CreatesTechnicianFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    private function action(): AssignTechnicianToBookingItemAction
    {
        return app(AssignTechnicianToBookingItemAction::class);
    }

    // 1 & 2. An eligible Technician can be assigned, and the assignment row
    // links exactly the requested Technician to the requested Booking Item.
    public function test_eligible_technician_can_be_assigned_to_a_pending_item(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $admin = $this->createAdminUser();
        $itemUuid = UuidBinary::toString($fixture['item']->id);

        $result = $this->action()->assign($itemUuid, $technician['uuid'], $admin);

        $this->assertSame(TechnicianAssignmentOutcome::ASSIGNED, $result->outcome);
        $this->assertNotNull($result->assignmentUuid);
        $this->assertSame($itemUuid, $result->bookingItemUuid);
        $this->assertSame($technician['uuid'], $result->technicianUuid);

        $row = $this->assignmentRow($result->assignmentUuid);
        $this->assertNotNull($row);
        $this->assertSame($fixture['item']->id, $row->booking_item_id);
        $this->assertSame(UuidBinary::toBinary($technician['uuid']), $row->technician_id);
        $this->assertSame($fixture['specialization_id'], (int) $row->specialization_id);
        $this->assertSame(UuidBinary::toBinary($admin), $row->assigned_by_user_id);
        $this->assertSame(1, (int) $row->is_primary);
        $this->assertNull($row->released_at);

        $freshItem = DB::table('booking_items')->where('id', $fixture['item']->id)->first();
        $this->assertSame('ASSIGNED', DB::table('booking_item_statuses')->where('id', $freshItem->status_id)->value('code'));
        $this->assertSame(1, DB::table('booking_item_status_history')->where('booking_item_id', $fixture['item']->id)->count());
    }

    // 3 & 15. A Technician whose only specialization does not match the
    // Booking Item's *actual* service (resolved from booking_items.service_id,
    // never a client-supplied service id - the Action signature has no such
    // parameter at all) is rejected.
    public function test_specialization_mismatch_is_rejected(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $otherSpecializationId = $this->createSpecialization();
        $technician = $this->createEligibleTechnician($otherSpecializationId);
        $admin = $this->createAdminUser();

        $result = $this->action()->assign(UuidBinary::toString($fixture['item']->id), $technician['uuid'], $admin);

        $this->assertSame(TechnicianAssignmentOutcome::SPECIALIZATION_MISMATCH, $result->outcome);
        $this->assertNull($this->activeAssignmentForItem($fixture['item']));
        $this->assertSame('PENDING_ASSIGNMENT', DB::table('booking_item_statuses')->where('id', DB::table('booking_items')->where('id', $fixture['item']->id)->value('status_id'))->value('code'));
    }

    // A service with no configured specialization at all cannot be
    // satisfied (technician_assignments.specialization_id is NOT NULL) -
    // distinct from an actual mismatch.
    public function test_unconfigured_service_specialization_blocks_assignment(): void
    {
        $fixture = $this->bookingWithAssignableItem(['with_specialization' => false]);
        $specializationId = $this->createSpecialization();
        $technician = $this->createEligibleTechnician($specializationId);
        $admin = $this->createAdminUser();

        $result = $this->action()->assign(UuidBinary::toString($fixture['item']->id), $technician['uuid'], $admin);

        $this->assertSame(TechnicianAssignmentOutcome::SERVICE_SPECIALIZATION_NOT_CONFIGURED, $result->outcome);
        $this->assertNull($this->activeAssignmentForItem($fixture['item']));
    }

    // 4. A Technician in a non-assignable status (data-driven via
    // technician_statuses.is_assignable, never a hardcoded status id/code).
    public function test_inactive_technician_is_rejected(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id'], ['status_id' => $this->technicianStatusId('INACTIVE')]);
        $admin = $this->createAdminUser();

        $result = $this->action()->assign(UuidBinary::toString($fixture['item']->id), $technician['uuid'], $admin);

        $this->assertSame(TechnicianAssignmentOutcome::TECHNICIAN_NOT_ELIGIBLE, $result->outcome);
        $this->assertNull($this->activeAssignmentForItem($fixture['item']));
    }

    // Every non-assignable status is rejected the same way - BUSY and
    // ON_LEAVE are not hardcoded exceptions.
    public function test_busy_and_on_leave_technicians_are_also_rejected(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $admin = $this->createAdminUser();

        foreach (['BUSY', 'ON_LEAVE'] as $statusCode) {
            $technician = $this->createEligibleTechnician($fixture['specialization_id'], ['status_id' => $this->technicianStatusId($statusCode)]);
            $result = $this->action()->assign(UuidBinary::toString($fixture['item']->id), $technician['uuid'], $admin);
            $this->assertSame(TechnicianAssignmentOutcome::TECHNICIAN_NOT_ELIGIBLE, $result->outcome, "Expected {$statusCode} technician to be rejected.");
        }
    }

    // 5. Unknown Technician.
    public function test_unknown_technician_is_rejected(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $admin = $this->createAdminUser();

        $result = $this->action()->assign(UuidBinary::toString($fixture['item']->id), (string) Str::uuid(), $admin);

        $this->assertSame(TechnicianAssignmentOutcome::TECHNICIAN_NOT_FOUND, $result->outcome);
    }

    // 6. Unknown Booking Item.
    public function test_unknown_booking_item_is_rejected(): void
    {
        $technician = $this->createTechnician();
        $admin = $this->createAdminUser();

        $result = $this->action()->assign((string) Str::uuid(), $technician, $admin);

        $this->assertSame(TechnicianAssignmentOutcome::ITEM_NOT_FOUND, $result->outcome);
    }

    // Actor eligibility: missing user vs. a real, non-admin user.
    public function test_actor_not_found_is_rejected(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $result = $this->action()->assign(UuidBinary::toString($fixture['item']->id), $technician['uuid'], (string) Str::uuid());

        $this->assertSame(TechnicianAssignmentOutcome::ACTOR_NOT_FOUND, $result->outcome);
        $this->assertNull($this->activeAssignmentForItem($fixture['item']));
    }

    public function test_actor_without_admin_role_is_rejected(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $result = $this->action()->assign(UuidBinary::toString($fixture['item']->id), $technician['uuid'], $fixture['customer']['user_uuid']);

        $this->assertSame(TechnicianAssignmentOutcome::ACTOR_NOT_AUTHORIZED, $result->outcome);
        $this->assertNull($this->activeAssignmentForItem($fixture['item']));
    }

    // 7, 11 & 12. Retrying the exact same assignment is a safe, idempotent
    // no-op - never a duplicate technician_assignments row and never a
    // duplicate booking_item_status_history row.
    public function test_retrying_the_same_assignment_is_idempotent(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $admin = $this->createAdminUser();
        $itemUuid = UuidBinary::toString($fixture['item']->id);

        $first = $this->action()->assign($itemUuid, $technician['uuid'], $admin);
        $second = $this->action()->assign($itemUuid, $technician['uuid'], $admin);
        $third = $this->action()->assign($itemUuid, $technician['uuid'], $admin);

        $this->assertSame(TechnicianAssignmentOutcome::ASSIGNED, $first->outcome);
        $this->assertSame(TechnicianAssignmentOutcome::ALREADY_ASSIGNED, $second->outcome);
        $this->assertSame(TechnicianAssignmentOutcome::ALREADY_ASSIGNED, $third->outcome);
        $this->assertSame($first->assignmentUuid, $second->assignmentUuid);
        $this->assertSame($first->assignmentUuid, $third->assignmentUuid);

        $this->assertSame(1, DB::table('technician_assignments')->where('booking_item_id', $fixture['item']->id)->count());
        $this->assertSame(1, DB::table('booking_item_status_history')->where('booking_item_id', $fixture['item']->id)->count());
    }

    // 8. DB unique constraint backstop: uq_technician_assignments_active_technician
    // rejects a second active row for the same (booking_item_id, technician_id)
    // even when attempted directly, bypassing the Action's own idempotency check.
    public function test_duplicate_active_assignment_is_rejected_by_the_database_constraint(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $admin = $this->createAdminUser();

        $this->action()->assign(UuidBinary::toString($fixture['item']->id), $technician['uuid'], $admin);

        $now = now();

        try {
            DB::table('technician_assignments')->insert([
                'id' => UuidBinary::toBinary(UuidBinary::generate()),
                'booking_item_id' => $fixture['item']->id,
                'technician_id' => UuidBinary::toBinary($technician['uuid']),
                'specialization_id' => $fixture['specialization_id'],
                'assigned_by_user_id' => UuidBinary::toBinary($admin),
                'is_primary' => 0,
                'assigned_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->fail('Expected a QueryException from uq_technician_assignments_active_technician.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('uq_technician_assignments_active_technician', $e->getMessage());
        }

        $this->assertSame(1, DB::table('technician_assignments')->where('booking_item_id', $fixture['item']->id)->count());
    }

    // 9 & 19. A different (eligible) Technician can never silently overwrite
    // an already-active assignment - assign() always rejects this, whether
    // the item just became ASSIGNED (simulating the loser of a race) or was
    // already ASSIGNED beforehand.
    public function test_a_different_technician_cannot_overwrite_an_active_assignment(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technicianA = $this->createEligibleTechnician($fixture['specialization_id']);
        $technicianB = $this->createEligibleTechnician($fixture['specialization_id']);
        $admin = $this->createAdminUser();
        $itemUuid = UuidBinary::toString($fixture['item']->id);

        $first = $this->action()->assign($itemUuid, $technicianA['uuid'], $admin);
        $this->assertSame(TechnicianAssignmentOutcome::ASSIGNED, $first->outcome);

        // A second caller - unaware the item was just claimed by Technician A -
        // attempts to assign a different, otherwise perfectly eligible Technician B.
        $second = $this->action()->assign($itemUuid, $technicianB['uuid'], $admin);

        $this->assertSame(TechnicianAssignmentOutcome::ASSIGNED_TO_ANOTHER_TECHNICIAN, $second->outcome);
        $this->assertSame($technicianA['uuid'], $second->previousTechnicianUuid);

        $active = $this->activeAssignmentForItem($fixture['item']);
        $this->assertSame(UuidBinary::toBinary($technicianA['uuid']), $active->technician_id);
        $this->assertSame(1, DB::table('technician_assignments')->where('booking_item_id', $fixture['item']->id)->count());
    }

    // 13. Payment stays SUCCESSFUL across assignment.
    public function test_payment_remains_successful_after_assignment(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $admin = $this->createAdminUser();

        $this->action()->assign(UuidBinary::toString($fixture['item']->id), $technician['uuid'], $admin);

        $fresh = DB::table('payment_attempts')->where('id', $fixture['payment']->id)->first();
        $this->assertSame('SUCCESSFUL', DB::table('payment_statuses')->where('id', $fresh->status_id)->value('code'));
        $this->assertSame((string) $fixture['payment']->confirmed_amount, (string) $fresh->confirmed_amount);
        $this->assertSame((string) $fixture['payment']->checkout_snapshot_hash, (string) $fresh->checkout_snapshot_hash);
    }

    // 14. Booking Item pricing snapshot is byte-for-byte unchanged by assignment.
    public function test_pricing_snapshot_is_unchanged_by_assignment(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $admin = $this->createAdminUser();
        $before = $fixture['item'];

        $this->action()->assign(UuidBinary::toString($before->id), $technician['uuid'], $admin);

        $after = DB::table('booking_items')->where('id', $before->id)->first();

        $this->assertSame($before->base_amount_snapshot, $after->base_amount_snapshot);
        $this->assertSame($before->unit_total_amount, $after->unit_total_amount);
        $this->assertSame($before->line_total_amount, $after->line_total_amount);
        $this->assertSame($before->pricing_breakdown, $after->pricing_breakdown);
        $this->assertSame($before->service_code_snapshot, $after->service_code_snapshot);
        $this->assertSame($before->service_name_snapshot, $after->service_name_snapshot);
    }

    // 16. A COMPLETED (terminal) item can never be assigned.
    public function test_completed_item_cannot_be_assigned(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $itemUuid = UuidBinary::toString($fixture['item']->id);
        $lifecycle = app(TransitionBookingItemStatusAction::class);
        $lifecycle->assign($itemUuid);
        $lifecycle->start($itemUuid);
        $lifecycle->complete($itemUuid);

        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $admin = $this->createAdminUser();

        $result = $this->action()->assign($itemUuid, $technician['uuid'], $admin);

        $this->assertSame(TechnicianAssignmentOutcome::ITEM_NOT_ELIGIBLE, $result->outcome);
        $this->assertSame('COMPLETED', $result->itemStatusFrom);
    }

    // 17. A CANCELLED item can never be assigned.
    public function test_cancelled_item_cannot_be_assigned(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $itemUuid = UuidBinary::toString($fixture['item']->id);
        app(TransitionBookingItemStatusAction::class)->cancel($itemUuid);

        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $admin = $this->createAdminUser();

        $result = $this->action()->assign($itemUuid, $technician['uuid'], $admin);

        $this->assertSame(TechnicianAssignmentOutcome::ITEM_NOT_ELIGIBLE, $result->outcome);
        $this->assertSame('CANCELLED', $result->itemStatusFrom);
    }

    // An IN_PROGRESS item cannot be assigned via assign() either - it is
    // already committed to its current Technician; reassign() is the only
    // path once work has started.
    public function test_in_progress_item_cannot_be_assigned_via_assign(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $itemUuid = UuidBinary::toString($fixture['item']->id);
        $lifecycle = app(TransitionBookingItemStatusAction::class);
        $lifecycle->assign($itemUuid);
        $lifecycle->start($itemUuid);

        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $admin = $this->createAdminUser();

        $result = $this->action()->assign($itemUuid, $technician['uuid'], $admin);

        $this->assertSame(TechnicianAssignmentOutcome::ITEM_NOT_ELIGIBLE, $result->outcome);
        $this->assertSame('IN_PROGRESS', $result->itemStatusFrom);
    }

    // 18. Technician double-booking: an overlapping slot on a different
    // Booking is rejected; a non-overlapping slot is not; the same Booking
    // (identical slot) is never treated as a conflict with itself.
    public function test_technician_double_booking_across_overlapping_bookings_is_rejected(): void
    {
        $specializationId = $this->createSpecialization();
        $admin = $this->createAdminUser();
        $sharedSlotOverrides = ['starts_at' => now()->addDays(3)->setTime(9, 0), 'ends_at' => now()->addDays(3)->setTime(11, 0)];

        $bookingOne = $this->bookingWithAssignableItem(['specialization_id' => $specializationId, 'slot' => $sharedSlotOverrides]);
        // Overlaps bookingOne's [09:00, 11:00) window by one hour.
        $overlappingSlot = ['starts_at' => now()->addDays(3)->setTime(10, 0), 'ends_at' => now()->addDays(3)->setTime(12, 0)];
        $bookingTwo = $this->bookingWithAssignableItem(['specialization_id' => $specializationId, 'slot' => $overlappingSlot]);
        // Does not overlap bookingOne's window at all.
        $laterSlot = ['starts_at' => now()->addDays(3)->setTime(14, 0), 'ends_at' => now()->addDays(3)->setTime(16, 0)];
        $bookingThree = $this->bookingWithAssignableItem(['specialization_id' => $specializationId, 'slot' => $laterSlot]);

        $technician = $this->createEligibleTechnician($specializationId);

        $first = $this->action()->assign(UuidBinary::toString($bookingOne['item']->id), $technician['uuid'], $admin);
        $this->assertSame(TechnicianAssignmentOutcome::ASSIGNED, $first->outcome);

        $overlapping = $this->action()->assign(UuidBinary::toString($bookingTwo['item']->id), $technician['uuid'], $admin);
        $this->assertSame(TechnicianAssignmentOutcome::TECHNICIAN_DOUBLE_BOOKED, $overlapping->outcome);
        $this->assertNull($this->activeAssignmentForItem($bookingTwo['item']));

        $nonOverlapping = $this->action()->assign(UuidBinary::toString($bookingThree['item']->id), $technician['uuid'], $admin);
        $this->assertSame(TechnicianAssignmentOutcome::ASSIGNED, $nonOverlapping->outcome);
    }

    // The exact same Technician may be assigned to multiple Booking Items
    // within the *same* Booking (identical appointment_slot) - the "one
    // technician for the whole booking" case, never flagged as a conflict.
    public function test_same_technician_can_be_assigned_to_multiple_items_of_the_same_booking(): void
    {
        $service = $this->createPricedCartService();
        $specializationId = $this->createSpecialization();
        $this->linkServiceSpecialization($service['uuid'], $specializationId);

        $customer = $this->createAuthenticatedCartCustomer();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid'], 'quantity' => 1])->assertStatus(201);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid'], 'quantity' => 1])->assertStatus(201);

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
        $items = DB::table('booking_items')->where('booking_id', $booking->id)->orderBy('display_order')->get();
        $this->assertCount(2, $items);

        $technician = $this->createEligibleTechnician($specializationId);
        $admin = $this->createAdminUser();

        $first = $this->action()->assign(UuidBinary::toString($items[0]->id), $technician['uuid'], $admin);
        $second = $this->action()->assign(UuidBinary::toString($items[1]->id), $technician['uuid'], $admin);

        $this->assertSame(TechnicianAssignmentOutcome::ASSIGNED, $first->outcome);
        $this->assertSame(TechnicianAssignmentOutcome::ASSIGNED, $second->outcome);
    }

    // 20. A forced DB failure mid-assignment (a genuine CHECK constraint
    // violation, not a mock) rolls back the whole Action atomically - no
    // technician_assignments row and no Booking Item lifecycle write survive.
    public function test_assignment_failure_rolls_back_everything(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $admin = $this->createAdminUser();

        try {
            // chk_technician_assignments_internal_note requires NULL or
            // 2-500 chars - a single character forces the INSERT itself to fail.
            $this->action()->assign(UuidBinary::toString($fixture['item']->id), $technician['uuid'], $admin, 'x');
            $this->fail('Expected a QueryException from chk_technician_assignments_internal_note.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('chk_technician_assignments_internal_note', $e->getMessage());
        }

        $this->assertNull($this->activeAssignmentForItem($fixture['item']));
        $this->assertSame(0, DB::table('technician_assignments')->where('booking_item_id', $fixture['item']->id)->count());
        $fresh = DB::table('booking_items')->where('id', $fixture['item']->id)->first();
        $this->assertSame('PENDING_ASSIGNMENT', DB::table('booking_item_statuses')->where('id', $fresh->status_id)->value('code'));
        $this->assertSame(0, DB::table('booking_item_status_history')->where('booking_item_id', $fixture['item']->id)->count());
    }

    // 21 & 22. Static regression guard - the Action never *uses*
    // PricingEngine or Stripe/the payment gateway, mirroring
    // BookingLifecycleTest::test_lifecycle_classes_never_reference_pricing_engine
    // (its own docblock names PricingEngine/Stripe by word to document what
    // it does *not* do, so this checks actual usage forms, not the bare word).
    public function test_assignment_action_never_references_pricing_engine_or_stripe(): void
    {
        $source = file_get_contents(app_path('Actions/Technician/AssignTechnicianToBookingItemAction.php'));

        $this->assertStringNotContainsString('use App\Support\Pricing\PricingEngine', $source);
        $this->assertStringNotContainsString('PricingEngine::', $source);
        $this->assertStringNotContainsString('new PricingEngine', $source);
        $this->assertDoesNotMatchRegularExpression('/\$[A-Za-z_]*[Pp]ricing[A-Za-z]*Engine/', $source);
        $this->assertStringNotContainsString('use App\Support\Payment\Gateway', $source);
        $this->assertStringNotContainsString('PaymentGateway::', $source);
        $this->assertStringNotContainsString('new FakePaymentGateway', $source);
    }

    // 23 & 24. No HTTP route exists for technician assignment at all in
    // Phase 8A - the customer-facing Booking read API is the only exposed
    // surface, and it is untouched (no technician data added, no raw binary
    // ids), so there is nothing for a customer to mutate or leak.
    public function test_no_customer_reachable_route_exists_for_technician_assignment(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $bookingUuid = UuidBinary::toString($fixture['booking']->id);
        $itemUuid = UuidBinary::toString($fixture['item']->id);

        $response = $this->postJson(
            "/api/v1/bookings/{$bookingUuid}/items/{$itemUuid}/assign-technician",
            ['technician_uuid' => (string) Str::uuid()],
            ['Authorization' => 'Bearer '.$fixture['customer']['access_token']]
        );

        $response->assertStatus(404);
    }

    public function test_booking_read_api_is_unaffected_by_assignment(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $admin = $this->createAdminUser();
        $this->action()->assign(UuidBinary::toString($fixture['item']->id), $technician['uuid'], $admin);

        $response = $this->getJson('/api/v1/bookings/'.UuidBinary::toString($fixture['booking']->id), ['Authorization' => 'Bearer '.$fixture['customer']['access_token']]);

        $response->assertStatus(200);
        $item = $response->json('data.booking.items.0');
        $this->assertArrayNotHasKey('technician', $item);
        $this->assertArrayNotHasKey('technician_assignment', $item);
        $this->assertSame('ASSIGNED', $item['status']);
    }

    // --- Reassignment (Section 7) ---

    // Reassignment replaces the active Technician atomically, preserves the
    // old assignment as a released, auditable history row, and never
    // regresses the Booking Item's own status.
    public function test_reassignment_replaces_the_active_technician_and_preserves_history(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technicianA = $this->createEligibleTechnician($fixture['specialization_id']);
        $technicianB = $this->createEligibleTechnician($fixture['specialization_id']);
        $admin = $this->createAdminUser();
        $itemUuid = UuidBinary::toString($fixture['item']->id);

        $first = $this->action()->assign($itemUuid, $technicianA['uuid'], $admin);

        $reassignment = $this->action()->reassign($itemUuid, $technicianB['uuid'], $admin, 'Original technician reported sick.');

        $this->assertSame(TechnicianAssignmentOutcome::REASSIGNED, $reassignment->outcome);
        $this->assertSame($technicianB['uuid'], $reassignment->technicianUuid);
        $this->assertSame($technicianA['uuid'], $reassignment->previousTechnicianUuid);

        $oldRow = $this->assignmentRow($first->assignmentUuid);
        $this->assertNotNull($oldRow->released_at);
        $this->assertSame(UuidBinary::toBinary($admin), $oldRow->released_by_user_id);
        $this->assertSame('Original technician reported sick.', $oldRow->release_reason);

        $newRow = $this->assignmentRow($reassignment->assignmentUuid);
        $this->assertNull($newRow->released_at);
        $this->assertSame(UuidBinary::toBinary($technicianB['uuid']), $newRow->technician_id);

        $active = $this->activeAssignmentForItem($fixture['item']);
        $this->assertSame($newRow->id, $active->id);

        // Booking Item lifecycle is untouched by reassignment - still exactly
        // the one history row assign() wrote.
        $this->assertSame(1, DB::table('booking_item_status_history')->where('booking_item_id', $fixture['item']->id)->count());
        $fresh = DB::table('booking_items')->where('id', $fixture['item']->id)->first();
        $this->assertSame('ASSIGNED', DB::table('booking_item_statuses')->where('id', $fresh->status_id)->value('code'));
    }

    public function test_reassign_to_the_same_technician_is_idempotent(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $admin = $this->createAdminUser();
        $itemUuid = UuidBinary::toString($fixture['item']->id);

        $first = $this->action()->assign($itemUuid, $technician['uuid'], $admin);
        $second = $this->action()->reassign($itemUuid, $technician['uuid'], $admin, 'No-op reassignment.');

        $this->assertSame(TechnicianAssignmentOutcome::ALREADY_ASSIGNED, $second->outcome);
        $this->assertSame($first->assignmentUuid, $second->assignmentUuid);
        $this->assertSame(1, DB::table('technician_assignments')->where('booking_item_id', $fixture['item']->id)->count());
    }

    // reassign() requires an existing active assignment - it is never a
    // back-door around assign()'s PENDING_ASSIGNMENT starting point.
    public function test_reassign_without_an_existing_assignment_is_rejected(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $admin = $this->createAdminUser();

        $result = $this->action()->reassign(UuidBinary::toString($fixture['item']->id), $technician['uuid'], $admin, 'No prior technician.');

        $this->assertSame(TechnicianAssignmentOutcome::ITEM_NOT_ELIGIBLE, $result->outcome);
        $this->assertSame('PENDING_ASSIGNMENT', $result->itemStatusFrom);
    }

    // reassign() is reachable from IN_PROGRESS and keeps the item IN_PROGRESS
    // under the new technician - never regressed back to ASSIGNED.
    public function test_reassign_is_reachable_from_in_progress_and_does_not_regress_status(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technicianA = $this->createEligibleTechnician($fixture['specialization_id']);
        $technicianB = $this->createEligibleTechnician($fixture['specialization_id']);
        $admin = $this->createAdminUser();
        $itemUuid = UuidBinary::toString($fixture['item']->id);

        $this->action()->assign($itemUuid, $technicianA['uuid'], $admin);
        app(TransitionBookingItemStatusAction::class)->start($itemUuid);

        $result = $this->action()->reassign($itemUuid, $technicianB['uuid'], $admin, 'Handover mid-visit.');

        $this->assertSame(TechnicianAssignmentOutcome::REASSIGNED, $result->outcome);
        $fresh = DB::table('booking_items')->where('id', $fixture['item']->id)->first();
        $this->assertSame('IN_PROGRESS', DB::table('booking_item_statuses')->where('id', $fresh->status_id)->value('code'));
    }

    public function test_reassign_on_a_completed_item_is_rejected(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technicianA = $this->createEligibleTechnician($fixture['specialization_id']);
        $technicianB = $this->createEligibleTechnician($fixture['specialization_id']);
        $admin = $this->createAdminUser();
        $itemUuid = UuidBinary::toString($fixture['item']->id);
        $lifecycle = app(TransitionBookingItemStatusAction::class);

        $this->action()->assign($itemUuid, $technicianA['uuid'], $admin);
        $lifecycle->start($itemUuid);
        $lifecycle->complete($itemUuid);

        $result = $this->action()->reassign($itemUuid, $technicianB['uuid'], $admin, 'Too late.');

        $this->assertSame(TechnicianAssignmentOutcome::ITEM_NOT_ELIGIBLE, $result->outcome);
        $this->assertSame('COMPLETED', $result->itemStatusFrom);
    }
}
