<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\TestCase;
use Illuminate\Support\Str;

class AdminJobExecutionTest extends TestCase
{
    use CreatesAdminFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    private function bookingWithTwoAssignableItems(): array
    {
        $specializationId = $this->createSpecialization();

        $serviceOne = $this->createPricedCartService();
        $serviceTwo = $this->createPricedCartService();

        $this->linkServiceSpecialization(
            $serviceOne['uuid'],
            $specializationId
        );

        $this->linkServiceSpecialization(
            $serviceTwo['uuid'],
            $specializationId
        );

        $customer = $this->createAuthenticatedCartCustomer();

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $serviceOne['uuid'],
            'quantity' => 1,
        ])->assertStatus(201);

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $serviceTwo['uuid'],
            'quantity' => 1,
        ])->assertStatus(201);

        [$areaId] = $this->twoDistinctAreaIds();

        $this->saveCheckoutLocation(
            $customer['access_token'],
            $this->locationPayload($areaId)
        )->assertStatus(200);

        $slot = $this->createAppointmentSlot();

        $this->createAppointmentHold(
            $customer['access_token'],
            $slot['uuid']
        )->assertStatus(201);

        $createResponse = $this->createPayment(
            $customer['access_token'],
            (string) Str::uuid()
        );

        $paymentRow = $this->paymentRow(
            $createResponse->json('data.payment.uuid')
        );

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $paymentRow->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $paymentRow->requested_amount,
        ]));

        $payment = $this->paymentRow(
            UuidBinary::toString($paymentRow->id)
        );

        $booking = $this->bookingRowForPayment($payment);

        $items = DB::table('booking_items')
            ->where('booking_id', $booking->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $items);

        return [
            'customer' => $customer,
            'booking' => $booking,
            'items' => $items,
            'specialization_id' => $specializationId,
        ];
    }

    private function startUrl(object $item): string
    {
        return '/api/v1/admin/booking-items/'.UuidBinary::toString($item->id).'/start-work';
    }

    private function completeUrl(object $item): string
    {
        return '/api/v1/admin/booking-items/'.UuidBinary::toString($item->id).'/complete-work';
    }

    private function bookingStatusForItem(object $item): string
{
    $bookingId = DB::table('booking_items')
        ->where('id', $item->id)
        ->value('booking_id');

    $statusId = DB::table('bookings')
        ->where('id', $bookingId)
        ->value('status_id');

    return (string) DB::table('booking_statuses')
        ->where('id', $statusId)
        ->value('code');
}

    /**
     * @return array{fixture: array, technician: array{uuid: string, specialization_id: int}, admin: array{user_uuid: string, access_token: string}}
     */
    private function assignedItem(): array
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->postJson('/api/v1/admin/booking-items/'.UuidBinary::toString($fixture['item']->id).'/assign-technician', [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        return ['fixture' => $fixture, 'technician' => $technician, 'admin' => $admin];
    }

    // 31. Admin can start an assigned job.
    public function test_admin_can_start_an_assigned_job(): void
    {
        ['fixture' => $fixture, 'technician' => $technician, 'admin' => $admin] = $this->assignedItem();

        $response = $this->postJson($this->startUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(200)->assertJson(['success' => true, 'data' => ['status' => 'IN_PROGRESS']]);
        $item = DB::table('booking_items')->where('id', $fixture['item']->id)->first();
        $this->assertSame('IN_PROGRESS', DB::table('booking_item_statuses')->where('id', $item->status_id)->value('code'));
    }

    // 32. Customer cannot start work.
    public function test_customer_cannot_start_work(): void
    {
        ['fixture' => $fixture, 'technician' => $technician] = $this->assignedItem();

        $this->postJson($this->startUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($fixture['customer']['access_token']))->assertStatus(401);
    }

    // 33. The wrong technician (not the currently active one) is rejected.
    public function test_wrong_technician_is_rejected_when_starting_work(): void
    {
        ['fixture' => $fixture, 'admin' => $admin] = $this->assignedItem();
        $someoneElse = $this->createTechnician();

        $this->postJson($this->startUrl($fixture['item']), [
            'technician_uuid' => $someoneElse,
        ], $this->bearer($admin['access_token']))->assertStatus(409);
    }

    // 34. Retry with the same technician is idempotent.
    public function test_start_work_retry_is_idempotent(): void
    {
        ['fixture' => $fixture, 'technician' => $technician, 'admin' => $admin] = $this->assignedItem();

        $this->postJson($this->startUrl($fixture['item']), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);
        $retry = $this->postJson($this->startUrl($fixture['item']), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']));

        $retry->assertStatus(200)->assertJson(['data' => ['status' => 'IN_PROGRESS']]);
    }

    // 35. Exactly one lifecycle history row is written for starting work.
    public function test_start_work_writes_exactly_one_history_row(): void
    {
        ['fixture' => $fixture, 'technician' => $technician, 'admin' => $admin] = $this->assignedItem();
        $beforeCount = DB::table('booking_item_status_history')->where('booking_item_id', $fixture['item']->id)->count();

        $this->postJson($this->startUrl($fixture['item']), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);
        $this->postJson($this->startUrl($fixture['item']), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);

        $afterCount = DB::table('booking_item_status_history')->where('booking_item_id', $fixture['item']->id)->count();
        $this->assertSame($beforeCount + 1, $afterCount);
    }

    // 36. Audit behavior: one BOOKING_ITEM_WORK_STARTED row per real start, none for retries.
    public function test_start_work_audit_behavior(): void
    {
        ['fixture' => $fixture, 'technician' => $technician, 'admin' => $admin] = $this->assignedItem();
        $itemUuid = UuidBinary::toString($fixture['item']->id);

        $this->postJson($this->startUrl($fixture['item']), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);
        $this->postJson($this->startUrl($fixture['item']), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);

        $logs = $this->auditLogsFor($itemUuid)->where('action_code', 'BOOKING_ITEM_WORK_STARTED');
        $this->assertSame(1, $logs->count());
    }

    /**
     * @return array{fixture: array, technician: array{uuid: string, specialization_id: int}, admin: array{user_uuid: string, access_token: string}}
     */
    private function inProgressItem(): array
    {
        $context = $this->assignedItem();
        $this->postJson($this->startUrl($context['fixture']['item']), [
            'technician_uuid' => $context['technician']['uuid'],
        ], $this->bearer($context['admin']['access_token']))->assertStatus(200);

        return $context;
    }

    // 37. Admin can complete an IN_PROGRESS job.
    public function test_admin_can_complete_an_in_progress_job(): void
    {
        ['fixture' => $fixture, 'technician' => $technician, 'admin' => $admin] = $this->inProgressItem();

        $response = $this->postJson($this->completeUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(200)->assertJson(['success' => true, 'data' => ['status' => 'COMPLETED']]);
        $item = DB::table('booking_items')->where('id', $fixture['item']->id)->first();
        $this->assertSame('COMPLETED', DB::table('booking_item_statuses')->where('id', $item->status_id)->value('code'));
        $this->assertNotNull($item->completed_at);
    }

    // 38. Complete-before-start is rejected.
    public function test_complete_before_start_is_rejected(): void
    {
        ['fixture' => $fixture, 'technician' => $technician, 'admin' => $admin] = $this->assignedItem();

        $this->postJson($this->completeUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(409);
    }

    public function test_customer_cannot_complete_work(): void
    {
        ['fixture' => $fixture, 'technician' => $technician] = $this->inProgressItem();

        $this->postJson($this->completeUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($fixture['customer']['access_token']))->assertStatus(401);
    }

    // 39 & 40. Retry is idempotent and completed_at never changes on retry.
    public function test_complete_work_retry_is_idempotent_and_completed_at_is_stable(): void
    {
        ['fixture' => $fixture, 'technician' => $technician, 'admin' => $admin] = $this->inProgressItem();

        $this->postJson($this->completeUrl($fixture['item']), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);
        $completedAtFirst = DB::table('booking_items')->where('id', $fixture['item']->id)->value('completed_at');

        usleep(2000);
        $retry = $this->postJson($this->completeUrl($fixture['item']), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']));
        $completedAtSecond = DB::table('booking_items')->where('id', $fixture['item']->id)->value('completed_at');

        $retry->assertStatus(200)->assertJson(['data' => ['status' => 'COMPLETED']]);
        $this->assertSame((string) $completedAtFirst, (string) $completedAtSecond);
    }

    // 41. Audit behavior: one BOOKING_ITEM_WORK_COMPLETED row per real completion, none for retries.
    public function test_complete_work_audit_behavior(): void
    {
        ['fixture' => $fixture, 'technician' => $technician, 'admin' => $admin] = $this->inProgressItem();
        $itemUuid = UuidBinary::toString($fixture['item']->id);

        $this->postJson($this->completeUrl($fixture['item']), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);
        $this->postJson($this->completeUrl($fixture['item']), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);

        $logs = $this->auditLogsFor($itemUuid)->where('action_code', 'BOOKING_ITEM_WORK_COMPLETED');
        $this->assertSame(1, $logs->count());
    }

    public function test_wrong_technician_is_rejected_when_completing_work(): void
    {
        ['fixture' => $fixture, 'admin' => $admin] = $this->inProgressItem();
        $someoneElse = $this->createTechnician();

        $this->postJson($this->completeUrl($fixture['item']), [
            'technician_uuid' => $someoneElse,
        ], $this->bearer($admin['access_token']))->assertStatus(409);
    }

    public function test_malformed_booking_item_uuid_returns_404_for_start_work(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $technician = $this->createTechnician();

        $this->postJson('/api/v1/admin/booking-items/not-a-uuid/start-work', [
            'technician_uuid' => $technician,
        ], $this->bearer($admin['access_token']))->assertStatus(404);
    }
    public function test_parent_booking_status_follows_item_lifecycle(): void
{
    ['fixture' => $fixture, 'technician' => $technician, 'admin' => $admin] = $this->assignedItem();

    // بعد تعيين الفني:
    // Booking: PAID -> ASSIGNED
    $this->assertSame(
        'ASSIGNED',
        $this->bookingStatusForItem($fixture['item'])
    );

    // يبدأ الفني العمل:
    // Booking: ASSIGNED -> IN_PROGRESS
    $this->postJson(
        $this->startUrl($fixture['item']),
        ['technician_uuid' => $technician['uuid']],
        $this->bearer($admin['access_token'])
    )->assertStatus(200);

    $this->assertSame(
        'IN_PROGRESS',
        $this->bookingStatusForItem($fixture['item'])
    );

    // تنتهي الخدمة:
    // وبما أن الحجز هنا يحتوي على Item واحد:
    // Booking: IN_PROGRESS -> COMPLETED
    $this->postJson(
        $this->completeUrl($fixture['item']),
        ['technician_uuid' => $technician['uuid']],
        $this->bearer($admin['access_token'])
    )->assertStatus(200);

    $this->assertSame(
        'COMPLETED',
        $this->bookingStatusForItem($fixture['item'])
    );
}
public function test_parent_booking_completes_only_after_all_items_are_completed(): void
{
    $admin = $this->createAndLoginAdmin(['ADMIN']);

    $fixture = $this->bookingWithTwoAssignableItems();

    $technician = $this->createEligibleTechnician(
        $fixture['specialization_id']
    );

    $itemOne = $fixture['items'][0];
    $itemTwo = $fixture['items'][1];

    /*
     * Assign the same technician to both items.
     * Same Booking, so this is allowed.
     */
    foreach ([$itemOne, $itemTwo] as $item) {
        $this->postJson(
            '/api/v1/admin/booking-items/'.
            UuidBinary::toString($item->id).
            '/assign-technician',
            [
                'technician_uuid' => $technician['uuid'],
            ],
            $this->bearer($admin['access_token'])
        )->assertStatus(201);
    }

    // Parent Booking must now be ASSIGNED.
    $booking = DB::table('bookings')
        ->where('id', $fixture['booking']->id)
        ->first();

    $this->assertSame(
        'ASSIGNED',
        DB::table('booking_statuses')
            ->where('id', $booking->status_id)
            ->value('code')
    );

    /*
     * Start Item 1.
     * Parent becomes IN_PROGRESS.
     */
    $this->postJson(
        $this->startUrl($itemOne),
        [
            'technician_uuid' => $technician['uuid'],
        ],
        $this->bearer($admin['access_token'])
    )->assertStatus(200);

    $this->assertSame(
        'IN_PROGRESS',
        $this->bookingStatusForItem($itemOne)
    );

    /*
     * Complete Item 1 only.
     *
     * Item 2 is still ASSIGNED,
     * therefore parent Booking MUST remain IN_PROGRESS.
     */
    $this->postJson(
        $this->completeUrl($itemOne),
        [
            'technician_uuid' => $technician['uuid'],
        ],
        $this->bearer($admin['access_token'])
    )->assertStatus(200);

    $this->assertSame(
        'IN_PROGRESS',
        $this->bookingStatusForItem($itemOne)
    );

    /*
     * Start and complete Item 2.
     */
    $this->postJson(
        $this->startUrl($itemTwo),
        [
            'technician_uuid' => $technician['uuid'],
        ],
        $this->bearer($admin['access_token'])
    )->assertStatus(200);

    $this->postJson(
        $this->completeUrl($itemTwo),
        [
            'technician_uuid' => $technician['uuid'],
        ],
        $this->bearer($admin['access_token'])
    )->assertStatus(200);

    /*
     * NOW every Booking Item is COMPLETED,
     * so the parent Booking must also be COMPLETED.
     */
    $this->assertSame(
        'COMPLETED',
        $this->bookingStatusForItem($itemTwo)
    );
}
public function test_parent_booking_history_is_not_duplicated_by_retries(): void
{
    ['fixture' => $fixture, 'technician' => $technician, 'admin' => $admin] = $this->assignedItem();

    $bookingId = $fixture['booking']->id;

    $afterAssign = DB::table('booking_status_history')
        ->where('booking_id', $bookingId)
        ->count();

    // Retry assignment.
    $this->postJson(
        '/api/v1/admin/booking-items/'.
        UuidBinary::toString($fixture['item']->id).
        '/assign-technician',
        [
            'technician_uuid' => $technician['uuid'],
        ],
        $this->bearer($admin['access_token'])
    )->assertStatus(200);

    $this->assertSame(
        $afterAssign,
        DB::table('booking_status_history')
            ->where('booking_id', $bookingId)
            ->count()
    );

    // First real start.
    $this->postJson(
        $this->startUrl($fixture['item']),
        ['technician_uuid' => $technician['uuid']],
        $this->bearer($admin['access_token'])
    )->assertStatus(200);

    $afterStart = DB::table('booking_status_history')
        ->where('booking_id', $bookingId)
        ->count();

    // Retry start.
    $this->postJson(
        $this->startUrl($fixture['item']),
        ['technician_uuid' => $technician['uuid']],
        $this->bearer($admin['access_token'])
    )->assertStatus(200);

    $this->assertSame(
        $afterStart,
        DB::table('booking_status_history')
            ->where('booking_id', $bookingId)
            ->count()
    );

    // First real completion.
    $this->postJson(
        $this->completeUrl($fixture['item']),
        ['technician_uuid' => $technician['uuid']],
        $this->bearer($admin['access_token'])
    )->assertStatus(200);

    $afterComplete = DB::table('booking_status_history')
        ->where('booking_id', $bookingId)
        ->count();

    // Retry completion.
    $this->postJson(
        $this->completeUrl($fixture['item']),
        ['technician_uuid' => $technician['uuid']],
        $this->bearer($admin['access_token'])
    )->assertStatus(200);

    $this->assertSame(
        $afterComplete,
        DB::table('booking_status_history')
            ->where('booking_id', $bookingId)
            ->count()
    );
}
}
