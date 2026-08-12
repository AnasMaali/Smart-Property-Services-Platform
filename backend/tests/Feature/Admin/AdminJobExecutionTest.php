<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\TestCase;

class AdminJobExecutionTest extends TestCase
{
    use CreatesAdminFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    private function startUrl(object $item): string
    {
        return '/api/v1/admin/booking-items/'.UuidBinary::toString($item->id).'/start-work';
    }

    private function completeUrl(object $item): string
    {
        return '/api/v1/admin/booking-items/'.UuidBinary::toString($item->id).'/complete-work';
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
}
