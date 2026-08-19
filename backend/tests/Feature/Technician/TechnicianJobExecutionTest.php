<?php

namespace Tests\Feature\Technician;

use App\Actions\Booking\TransitionBookingItemStatusAction;
use App\Actions\Technician\AssignTechnicianToBookingItemAction;
use App\Actions\Technician\CompleteTechnicianJobAction;
use App\Actions\Technician\StartTechnicianJobAction;
use App\Support\Booking\BookingItemStatuses;
use App\Support\Technician\TechnicianAssignmentOutcome;
use App\Support\Technician\TechnicianJobOutcome;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Technician\Concerns\CreatesTechnicianFixtures;
use Tests\TestCase;

class TechnicianJobExecutionTest extends TestCase
{
    use CreatesTechnicianFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    private function startAction(): StartTechnicianJobAction
    {
        return app(StartTechnicianJobAction::class);
    }

    private function completeAction(): CompleteTechnicianJobAction
    {
        return app(CompleteTechnicianJobAction::class);
    }

    /**
     * A Booking Item already ASSIGNED to one eligible Technician, plus the
     * admin actor uuid used to assign it.
     *
     * @return array{fixture: array, admin: string, technician: array{uuid: string, specialization_id: int}, itemUuid: string}
     */
    private function assignedJobFixture(): array
    {
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $admin = $this->createAdminUser();
        $itemUuid = UuidBinary::toString($fixture['item']->id);

        $assignResult = app(AssignTechnicianToBookingItemAction::class)->assign($itemUuid, $technician['uuid'], $admin);
        $this->assertSame(TechnicianAssignmentOutcome::ASSIGNED, $assignResult->outcome);

        return ['fixture' => $fixture, 'admin' => $admin, 'technician' => $technician, 'itemUuid' => $itemUuid];
    }

    /**
     * A Booking Item already IN_PROGRESS under one eligible Technician.
     *
     * @return array{fixture: array, admin: string, technician: array{uuid: string, specialization_id: int}, itemUuid: string}
     */
    private function startedJobFixture(): array
    {
        $job = $this->assignedJobFixture();
        $result = $this->startAction()->start($job['itemUuid'], $job['technician']['uuid'], $job['admin']);
        $this->assertSame(TechnicianJobOutcome::STARTED, $result->outcome);

        return $job;
    }

    private function itemStatusCode(string $itemUuidBinary): string
    {
        $statusId = DB::table('booking_items')->where('id', $itemUuidBinary)->value('status_id');

        return DB::table('booking_item_statuses')->where('id', $statusId)->value('code');
    }

    // --- Start Work ---

    // 1 & 2. An assigned job can start, and start() moves ASSIGNED -> IN_PROGRESS.
    public function test_assigned_job_can_start(): void
    {
        $job = $this->assignedJobFixture();

        $result = $this->startAction()->start($job['itemUuid'], $job['technician']['uuid'], $job['admin']);

        $this->assertSame(TechnicianJobOutcome::STARTED, $result->outcome);
        $this->assertSame('ASSIGNED', $result->itemStatusFrom);
        $this->assertSame('IN_PROGRESS', $result->itemStatusTo);
        $this->assertSame('IN_PROGRESS', $this->itemStatusCode($job['fixture']['item']->id));
    }

    // 3 & 4. Only the exact active Technician may start the job - a
    // different, otherwise unrelated Technician is rejected.
    public function test_wrong_technician_is_rejected(): void
    {
        $job = $this->assignedJobFixture();
        $otherTechnician = $this->createEligibleTechnician($job['technician']['specialization_id']);

        $result = $this->startAction()->start($job['itemUuid'], $otherTechnician['uuid'], $job['admin']);

        $this->assertSame(TechnicianJobOutcome::ASSIGNMENT_MISMATCH, $result->outcome);
        $this->assertSame($job['technician']['uuid'], $result->technicianUuid);
        $this->assertSame('ASSIGNED', $this->itemStatusCode($job['fixture']['item']->id));
    }

    // 5 & 6 & 29. Once reassigned, the old Technician can never start the
    // job (a released assignment is rejected), while the new active
    // Technician can - a deterministic simulation of the reassignment race.
    public function test_old_technician_is_rejected_after_reassignment(): void
    {
        $job = $this->assignedJobFixture();
        $technicianB = $this->createEligibleTechnician($job['technician']['specialization_id']);

        $reassignResult = app(AssignTechnicianToBookingItemAction::class)->reassign($job['itemUuid'], $technicianB['uuid'], $job['admin'], 'Technician A unavailable.');
        $this->assertSame(TechnicianAssignmentOutcome::REASSIGNED, $reassignResult->outcome);

        $staleAttempt = $this->startAction()->start($job['itemUuid'], $job['technician']['uuid'], $job['admin']);
        $this->assertSame(TechnicianJobOutcome::ASSIGNMENT_MISMATCH, $staleAttempt->outcome);
        $this->assertSame('ASSIGNED', $this->itemStatusCode($job['fixture']['item']->id));

        $currentAttempt = $this->startAction()->start($job['itemUuid'], $technicianB['uuid'], $job['admin']);
        $this->assertSame(TechnicianJobOutcome::STARTED, $currentAttempt->outcome);
        $this->assertSame('IN_PROGRESS', $this->itemStatusCode($job['fixture']['item']->id));
    }

    // A Booking Item with no active assignment at all (defensive - should
    // not occur through the normal assign() -> start() flow, but never assumed).
    public function test_item_with_no_active_assignment_cannot_start(): void
    {
        $job = $this->assignedJobFixture();

        DB::table('technician_assignments')
            ->where('booking_item_id', $job['fixture']['item']->id)
            ->update([
                'released_at' => now()->addSecond()->format('Y-m-d H:i:s.u'),
                'released_by_user_id' => UuidBinary::toBinary($job['admin']),
                'release_reason' => 'QA forced release, item left ASSIGNED.',
            ]);

        $result = $this->startAction()->start($job['itemUuid'], $job['technician']['uuid'], $job['admin']);

        $this->assertSame(TechnicianJobOutcome::NO_ACTIVE_ASSIGNMENT, $result->outcome);
    }

    // 7. An item still PENDING_ASSIGNMENT (never assigned) cannot start.
    public function test_unassigned_item_cannot_start(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $admin = $this->createAdminUser();
        $itemUuid = UuidBinary::toString($fixture['item']->id);

        $result = $this->startAction()->start($itemUuid, $technician['uuid'], $admin);

        $this->assertSame(TechnicianJobOutcome::ITEM_NOT_ELIGIBLE, $result->outcome);
        $this->assertSame('PENDING_ASSIGNMENT', $result->itemStatusFrom);
    }

    // 8. A cancelled item cannot start.
    public function test_cancelled_item_cannot_start(): void
    {
        $job = $this->assignedJobFixture();
        app(TransitionBookingItemStatusAction::class)->cancel($job['itemUuid']);

        $result = $this->startAction()->start($job['itemUuid'], $job['technician']['uuid'], $job['admin']);

        $this->assertSame(TechnicianJobOutcome::ITEM_NOT_ELIGIBLE, $result->outcome);
        $this->assertSame('CANCELLED', $result->itemStatusFrom);
    }

    // 9. A COMPLETED item cannot be started again ("start after complete").
    public function test_completed_item_cannot_start(): void
    {
        $job = $this->startedJobFixture();
        $completeResult = $this->completeAction()->complete($job['itemUuid'], $job['technician']['uuid'], $job['admin']);
        $this->assertSame(TechnicianJobOutcome::COMPLETED, $completeResult->outcome);

        $result = $this->startAction()->start($job['itemUuid'], $job['technician']['uuid'], $job['admin']);

        $this->assertSame(TechnicianJobOutcome::ITEM_NOT_ELIGIBLE, $result->outcome);
        $this->assertSame('COMPLETED', $result->itemStatusFrom);
    }

    // 10, 11 & 12. Retrying start() is idempotent - never a duplicate
    // history row, and status_changed_at never mutates on retry.
    public function test_repeated_start_is_idempotent(): void
    {
        $job = $this->assignedJobFixture();

        $first = $this->startAction()->start($job['itemUuid'], $job['technician']['uuid'], $job['admin']);
        $afterFirst = DB::table('booking_items')->where('id', $job['fixture']['item']->id)->first();

        $second = $this->startAction()->start($job['itemUuid'], $job['technician']['uuid'], $job['admin']);
        $third = $this->startAction()->start($job['itemUuid'], $job['technician']['uuid'], $job['admin']);
        $afterRetries = DB::table('booking_items')->where('id', $job['fixture']['item']->id)->first();

        $this->assertSame(TechnicianJobOutcome::STARTED, $first->outcome);
        $this->assertSame(TechnicianJobOutcome::ALREADY_STARTED, $second->outcome);
        $this->assertSame(TechnicianJobOutcome::ALREADY_STARTED, $third->outcome);

        $this->assertSame(
            1,
            DB::table('booking_item_status_history')
                ->where('booking_item_id', $job['fixture']['item']->id)
                ->where('to_status_id', BookingItemStatuses::id('IN_PROGRESS'))
                ->count()
        );
        $this->assertSame($afterFirst->status_changed_at, $afterRetries->status_changed_at);
        $this->assertSame($afterFirst->updated_at, $afterRetries->updated_at);
    }

    // --- Complete Work ---

    // 13 & 14. An IN_PROGRESS job can complete, moving IN_PROGRESS -> COMPLETED.
    public function test_in_progress_job_can_complete(): void
    {
        $job = $this->startedJobFixture();

        $result = $this->completeAction()->complete($job['itemUuid'], $job['technician']['uuid'], $job['admin']);

        $this->assertSame(TechnicianJobOutcome::COMPLETED, $result->outcome);
        $this->assertSame('IN_PROGRESS', $result->itemStatusFrom);
        $this->assertSame('COMPLETED', $result->itemStatusTo);
        $this->assertSame('COMPLETED', $this->itemStatusCode($job['fixture']['item']->id));
        $this->assertNotNull(DB::table('booking_items')->where('id', $job['fixture']['item']->id)->value('completed_at'));
    }

    // 15. Complete before Start is rejected (item still ASSIGNED).
    public function test_complete_before_start_is_rejected(): void
    {
        $job = $this->assignedJobFixture();

        $result = $this->completeAction()->complete($job['itemUuid'], $job['technician']['uuid'], $job['admin']);

        $this->assertSame(TechnicianJobOutcome::ITEM_NOT_ELIGIBLE, $result->outcome);
        $this->assertSame('ASSIGNED', $result->itemStatusFrom);
    }

    // 16, 17 & 18. Retrying complete() is idempotent - never a duplicate
    // history row, and completed_at never mutates on retry.
    public function test_repeated_complete_is_idempotent(): void
    {
        $job = $this->startedJobFixture();

        $first = $this->completeAction()->complete($job['itemUuid'], $job['technician']['uuid'], $job['admin']);
        $afterFirst = DB::table('booking_items')->where('id', $job['fixture']['item']->id)->first();

        $second = $this->completeAction()->complete($job['itemUuid'], $job['technician']['uuid'], $job['admin']);
        $third = $this->completeAction()->complete($job['itemUuid'], $job['technician']['uuid'], $job['admin']);
        $afterRetries = DB::table('booking_items')->where('id', $job['fixture']['item']->id)->first();

        $this->assertSame(TechnicianJobOutcome::COMPLETED, $first->outcome);
        $this->assertSame(TechnicianJobOutcome::ALREADY_COMPLETED, $second->outcome);
        $this->assertSame(TechnicianJobOutcome::ALREADY_COMPLETED, $third->outcome);

        $this->assertSame(
            1,
            DB::table('booking_item_status_history')
                ->where('booking_item_id', $job['fixture']['item']->id)
                ->where('to_status_id', BookingItemStatuses::id('COMPLETED'))
                ->count()
        );
        $this->assertSame($afterFirst->completed_at, $afterRetries->completed_at);
        $this->assertSame($afterFirst->status_changed_at, $afterRetries->status_changed_at);
    }

    // A Technician reassigned mid-execution: the old Technician can no
    // longer complete the job; the new active Technician can.
    public function test_old_technician_is_rejected_after_reassignment_during_execution(): void
    {
        $job = $this->startedJobFixture();
        $technicianB = $this->createEligibleTechnician($job['technician']['specialization_id']);

        $reassignResult = app(AssignTechnicianToBookingItemAction::class)->reassign($job['itemUuid'], $technicianB['uuid'], $job['admin'], 'Handover mid-visit.');
        $this->assertSame(TechnicianAssignmentOutcome::REASSIGNED, $reassignResult->outcome);
        $this->assertSame('IN_PROGRESS', $this->itemStatusCode($job['fixture']['item']->id));

        $staleAttempt = $this->completeAction()->complete($job['itemUuid'], $job['technician']['uuid'], $job['admin']);
        $this->assertSame(TechnicianJobOutcome::ASSIGNMENT_MISMATCH, $staleAttempt->outcome);

        $currentAttempt = $this->completeAction()->complete($job['itemUuid'], $technicianB['uuid'], $job['admin']);
        $this->assertSame(TechnicianJobOutcome::COMPLETED, $currentAttempt->outcome);
    }

    // --- Authorization boundary ---

    public function test_unknown_actor_is_rejected(): void
    {
        $job = $this->assignedJobFixture();

        $result = $this->startAction()->start($job['itemUuid'], $job['technician']['uuid'], (string) Str::uuid());

        $this->assertSame(TechnicianJobOutcome::ACTOR_NOT_FOUND, $result->outcome);
        $this->assertSame('ASSIGNED', $this->itemStatusCode($job['fixture']['item']->id));
    }

    // 26. A Customer (a real, authenticated, non-admin user) cannot invoke
    // the internal Start/Complete Work Actions directly.
    public function test_customer_cannot_invoke_start_or_complete(): void
    {
        $job = $this->assignedJobFixture();
        $customerUuid = $job['fixture']['customer']['user_uuid'];

        $startResult = $this->startAction()->start($job['itemUuid'], $job['technician']['uuid'], $customerUuid);
        $this->assertSame(TechnicianJobOutcome::ACTOR_NOT_AUTHORIZED, $startResult->outcome);
        $this->assertSame('ASSIGNED', $this->itemStatusCode($job['fixture']['item']->id));

        $completeResult = $this->completeAction()->complete($job['itemUuid'], $job['technician']['uuid'], $customerUuid);
        $this->assertSame(TechnicianJobOutcome::ACTOR_NOT_AUTHORIZED, $completeResult->outcome);
    }

    // 27. No arbitrary HTTP status/lifecycle endpoint exists for a Customer
    // to reach - Phase 8B, like Phase 7B/8A before it, adds no new route.
    public function test_no_customer_reachable_route_exists_for_start_or_complete(): void
    {
        $job = $this->assignedJobFixture();
        $bookingUuid = UuidBinary::toString($job['fixture']['booking']->id);
        $headers = ['Authorization' => 'Bearer '.$job['fixture']['customer']['access_token']];

        foreach (['start-work', 'complete-work', 'status'] as $suffix) {
            $response = $this->postJson("/api/v1/bookings/{$bookingUuid}/items/{$job['itemUuid']}/{$suffix}", [], $headers);
            $response->assertStatus(404);
        }

        $patchResponse = $this->patchJson("/api/v1/bookings/{$bookingUuid}/items/{$job['itemUuid']}/status", ['status' => 'IN_PROGRESS'], $headers);
        $patchResponse->assertStatus(404);
    }

    // --- Financial / snapshot / sibling isolation ---

    // 19 & 20. Payment stays SUCCESSFUL, with an unchanged confirmed amount
    // and checkout snapshot hash, across both Start and Complete.
    public function test_payment_remains_successful_and_unchanged_through_start_and_complete(): void
    {
        $job = $this->assignedJobFixture();
        $paymentBefore = $job['fixture']['payment'];

        $this->startAction()->start($job['itemUuid'], $job['technician']['uuid'], $job['admin']);
        $this->completeAction()->complete($job['itemUuid'], $job['technician']['uuid'], $job['admin']);

        $paymentAfter = DB::table('payment_attempts')->where('id', $paymentBefore->id)->first();
        $this->assertSame('SUCCESSFUL', DB::table('payment_statuses')->where('id', $paymentAfter->status_id)->value('code'));
        $this->assertSame((string) $paymentBefore->confirmed_amount, (string) $paymentAfter->confirmed_amount);
        $this->assertSame((string) $paymentBefore->checkout_snapshot_hash, (string) $paymentAfter->checkout_snapshot_hash);
    }

    // 21 & 22. Booking Item pricing snapshot columns are byte-for-byte
    // unchanged by Start and Complete.
    public function test_pricing_snapshot_is_unchanged_through_start_and_complete(): void
    {
        $job = $this->assignedJobFixture();
        $before = $job['fixture']['item'];

        $this->startAction()->start($job['itemUuid'], $job['technician']['uuid'], $job['admin']);
        $this->completeAction()->complete($job['itemUuid'], $job['technician']['uuid'], $job['admin']);

        $after = DB::table('booking_items')->where('id', $before->id)->first();

        $this->assertSame($before->base_amount_snapshot, $after->base_amount_snapshot);
        $this->assertSame($before->unit_total_amount, $after->unit_total_amount);
        $this->assertSame($before->line_total_amount, $after->line_total_amount);
        $this->assertSame($before->pricing_breakdown, $after->pricing_breakdown);
        $this->assertSame($before->service_code_snapshot, $after->service_code_snapshot);
        $this->assertSame($before->service_name_snapshot, $after->service_name_snapshot);
    }

    // 23 & 30. Starting/completing one Booking Item never touches a sibling
    // item in the same Booking, and the parent Booking's own status_id is
    // untouched - Phase 8B keeps the Booking/Booking Item lifecycles
    // independent, exactly like Phase 7B/8A before it.
    public function test_sibling_items_and_parent_booking_are_untouched(): void
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
        $item1Uuid = UuidBinary::toString($items[0]->id);

        // CreateBookingFromSuccessfulPaymentAction already writes the initial
        // PAID booking_status_history row at conversion time (Phase 7A) - the
        // baseline here is that row, not zero.
        $bookingHistoryCountBefore = DB::table('booking_status_history')->where('booking_id', $booking->id)->count();

        app(AssignTechnicianToBookingItemAction::class)->assign($item1Uuid, $technician['uuid'], $admin);
        $this->startAction()->start($item1Uuid, $technician['uuid'], $admin);

        $sibling = DB::table('booking_items')->where('id', $items[1]->id)->first();
        $this->assertSame('PENDING_ASSIGNMENT', $this->itemStatusCode($items[1]->id));
        $this->assertSame(0, DB::table('booking_item_status_history')->where('booking_item_id', $items[1]->id)->count());
        $this->assertSame($items[1]->updated_at, $sibling->updated_at);

        $bookingAfter = DB::table('bookings')
        ->where('id', $booking->id)
        ->first();

    $this->assertSame(
        'IN_PROGRESS',
        DB::table('booking_statuses')
            ->where('id', $bookingAfter->status_id)
            ->value('code')
    );

    $this->assertSame(
        $bookingHistoryCountBefore + 2,
        DB::table('booking_status_history')
            ->where('booking_id', $booking->id)
            ->count()
    );
    }

    // --- Static regression guards ---

    // 24 & 25. Neither Action ever uses PricingEngine or Stripe/PaymentGateway.
    public function test_start_and_complete_actions_never_reference_pricing_engine_or_stripe(): void
    {
        foreach (['Actions/Technician/StartTechnicianJobAction.php', 'Actions/Technician/CompleteTechnicianJobAction.php'] as $relativePath) {
            $source = file_get_contents(app_path($relativePath));

            $this->assertStringNotContainsString('use App\Support\Pricing\PricingEngine', $source);
            $this->assertStringNotContainsString('PricingEngine::', $source);
            $this->assertStringNotContainsString('new PricingEngine', $source);
            $this->assertDoesNotMatchRegularExpression('/\$[A-Za-z_]*[Pp]ricing[A-Za-z]*Engine/', $source);
            $this->assertStringNotContainsString('use App\Support\Payment\Gateway', $source);
            $this->assertStringNotContainsString('PaymentGateway::', $source);
            $this->assertStringNotContainsString('new FakePaymentGateway', $source);
        }
    }

    // 28. A forced DB failure mid-transition (a genuine CHECK constraint
    // violation, not a mock) rolls back the whole Action atomically - no
    // status write and no history row survive.

    public function test_start_after_mutation_failure_rolls_back_everything(): void
    {
        $job = $this->assignedJobFixture();

        try {
            $this->startAction()->start(
                $job['itemUuid'],
                $job['technician']['uuid'],
                $job['admin'],
                'Start work.',
                function (): void {
                    throw new \RuntimeException('Forced audit failure.');
                }
            );

            $this->fail('Expected forced audit failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Forced audit failure.', $e->getMessage());
        }

        $this->assertSame(
            'ASSIGNED',
            $this->itemStatusCode($job['fixture']['item']->id)
        );

        $this->assertSame(
            0,
            DB::table('booking_item_status_history')
                ->where('booking_item_id', $job['fixture']['item']->id)
                ->where(
                    'to_status_id',
                    BookingItemStatuses::id('IN_PROGRESS')
                )
                ->count()
        );

        $bookingStatusId = DB::table('bookings')
            ->where('id', $job['fixture']['booking']->id)
            ->value('status_id');

        $this->assertSame(
            'ASSIGNED',
            DB::table('booking_statuses')
                ->where('id', $bookingStatusId)
                ->value('code')
        );
    }

    public function test_complete_after_mutation_failure_rolls_back_everything(): void
    {
        $job = $this->startedJobFixture();

        try {
            $this->completeAction()->complete(
                $job['itemUuid'],
                $job['technician']['uuid'],
                $job['admin'],
                'Complete work.',
                function (): void {
                    throw new \RuntimeException('Forced audit failure.');
                }
            );

            $this->fail('Expected forced audit failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Forced audit failure.', $e->getMessage());
        }

        $this->assertSame(
            'IN_PROGRESS',
            $this->itemStatusCode($job['fixture']['item']->id)
        );

        $this->assertNull(
            DB::table('booking_items')
                ->where('id', $job['fixture']['item']->id)
                ->value('completed_at')
        );

        $this->assertSame(
            0,
            DB::table('booking_item_status_history')
                ->where('booking_item_id', $job['fixture']['item']->id)
                ->where(
                    'to_status_id',
                    BookingItemStatuses::id('COMPLETED')
                )
                ->count()
        );

        $bookingStatusId = DB::table('bookings')
            ->where('id', $job['fixture']['booking']->id)
            ->value('status_id');

        $this->assertSame(
            'IN_PROGRESS',
            DB::table('booking_statuses')
                ->where('id', $bookingStatusId)
                ->value('code')
        );
    }

    public function test_start_failure_rolls_back_everything(): void
    {
        $job = $this->assignedJobFixture();

        try {
            // chk_item_status_history_reason requires NULL or 2-500 chars -
            // a single character forces the history INSERT itself to fail.
            $this->startAction()->start($job['itemUuid'], $job['technician']['uuid'], $job['admin'], 'x');
            $this->fail('Expected a QueryException from chk_item_status_history_reason.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('chk_item_status_history_reason', $e->getMessage());
        }

        $this->assertSame('ASSIGNED', $this->itemStatusCode($job['fixture']['item']->id));
        $this->assertSame(0, DB::table('booking_item_status_history')->where('booking_item_id', $job['fixture']['item']->id)->where('to_status_id', BookingItemStatuses::id('IN_PROGRESS'))->count());
    }

    public function test_complete_failure_rolls_back_everything(): void
    {
        $job = $this->startedJobFixture();

        try {
            $this->completeAction()->complete($job['itemUuid'], $job['technician']['uuid'], $job['admin'], 'x');
            $this->fail('Expected a QueryException from chk_item_status_history_reason.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('chk_item_status_history_reason', $e->getMessage());
        }

        $this->assertSame('IN_PROGRESS', $this->itemStatusCode($job['fixture']['item']->id));
        $this->assertNull(DB::table('booking_items')->where('id', $job['fixture']['item']->id)->value('completed_at'));
        $this->assertSame(0, DB::table('booking_item_status_history')->where('booking_item_id', $job['fixture']['item']->id)->where('to_status_id', BookingItemStatuses::id('COMPLETED'))->count());
    }
}
