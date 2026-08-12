<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\TestCase;

class AdminAssignmentTest extends TestCase
{
    use CreatesAdminFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    private function assignUrl(object $item): string
    {
        return '/api/v1/admin/booking-items/'.UuidBinary::toString($item->id).'/assign-technician';
    }

    private function reassignUrl(object $item): string
    {
        return '/api/v1/admin/booking-items/'.UuidBinary::toString($item->id).'/reassign-technician';
    }

    // 16. Admin can assign a technician.
    public function test_admin_can_assign_an_eligible_technician(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(201)->assertJson([
            'success' => true,
            'data' => ['assignment' => ['technician' => ['uuid' => $technician['uuid']]]],
        ]);
    }

    // 17. The actor is derived from auth.admin, never from the request body.
    public function test_assignment_actor_is_derived_from_auth_admin_not_the_request_body(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $otherAdmin = $this->createAdminUser();
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
            'assigned_by_user_uuid' => $otherAdmin,
            'is_primary' => false,
            'assigned_at' => '2000-01-01T00:00:00Z',
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        $assignment = DB::table('technician_assignments')->where('id', UuidBinary::toBinary($response->json('data.assignment.uuid')))->first();
        $this->assertSame(UuidBinary::toBinary($admin['user_uuid']), $assignment->assigned_by_user_id);
        $this->assertNotSame(UuidBinary::toBinary($otherAdmin), $assignment->assigned_by_user_id);
        $this->assertSame(1, (int) $assignment->is_primary);
    }

    // 18. Customer cannot assign.
    public function test_customer_cannot_assign_a_technician(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($fixture['customer']['access_token']))->assertStatus(401);
    }

    // 19. Specialization mismatch maps to 422.
    public function test_specialization_mismatch_maps_to_422(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $otherSpecializationId = $this->createSpecialization();
        $technician = $this->createEligibleTechnician($otherSpecializationId);

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(422);
    }

    // 20. An ineligible (e.g. INACTIVE) technician maps to 409.
    public function test_ineligible_technician_maps_to_409(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id'], ['status_id' => $this->technicianStatusId('INACTIVE')]);

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(409);
    }

    // 21. Double-booking maps to 409.
    public function test_double_booking_maps_to_409(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $slot = ['starts_at' => now()->addDays(2)->setTime(9, 0), 'ends_at' => now()->addDays(2)->setTime(11, 0)];
        $fixtureA = $this->bookingWithAssignableItem(['slot' => $slot]);
        $technician = $this->createEligibleTechnician($fixtureA['specialization_id']);

        $this->postJson($this->assignUrl($fixtureA['item']), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))
            ->assertStatus(201);

        $overlappingSlot = ['starts_at' => now()->addDays(2)->setTime(10, 0), 'ends_at' => now()->addDays(2)->setTime(12, 0)];
        $fixtureB = $this->bookingWithAssignableItem(['specialization_id' => $fixtureA['specialization_id'], 'slot' => $overlappingSlot]);

        $this->postJson($this->assignUrl($fixtureB['item']), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))
            ->assertStatus(409);
    }

    // 22. Duplicate assignment retry is idempotent (200, not 201, no duplicate row).
    public function test_duplicate_assignment_retry_is_idempotent(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $first = $this->postJson($this->assignUrl($fixture['item']), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))
            ->assertStatus(201);
        $second = $this->postJson($this->assignUrl($fixture['item']), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $this->assertSame($first->json('data.assignment.uuid'), $second->json('data.assignment.uuid'));
        $this->assertSame(1, DB::table('technician_assignments')->where('booking_item_id', $fixture['item']->id)->count());
    }

    // 23. Assignment creates exactly one lifecycle history row.
    public function test_assignment_creates_exactly_one_lifecycle_history_row(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->postJson($this->assignUrl($fixture['item']), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))
            ->assertStatus(201);

        $this->assertSame(1, DB::table('booking_item_status_history')->where('booking_item_id', $fixture['item']->id)->count());
    }

    // 24. A successful assignment writes an admin_audit_logs row; a rejected one does not.
    public function test_successful_assignment_writes_an_audit_row(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $itemUuid = UuidBinary::toString($fixture['item']->id);

        $this->postJson($this->assignUrl($fixture['item']), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))
            ->assertStatus(201);

        $logs = $this->auditLogsFor($itemUuid);
        $this->assertSame(1, $logs->count());
        $this->assertSame('TECHNICIAN_ASSIGNED', $logs->first()->action_code);
        $this->assertSame(UuidBinary::toBinary($admin['user_uuid']), $logs->first()->admin_user_id);
        $this->assertSame(1, (int) $logs->first()->was_successful);
    }

    public function test_rejected_assignment_does_not_write_an_audit_row(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $otherSpecializationId = $this->createSpecialization();
        $technician = $this->createEligibleTechnician($otherSpecializationId);
        $itemUuid = UuidBinary::toString($fixture['item']->id);

        $this->postJson($this->assignUrl($fixture['item']), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))
            ->assertStatus(422);

        $this->assertSame(0, $this->auditLogsFor($itemUuid)->count());
    }

    public function test_idempotent_retry_does_not_write_a_second_audit_row(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $itemUuid = UuidBinary::toString($fixture['item']->id);

        $this->postJson($this->assignUrl($fixture['item']), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(201);
        $this->postJson($this->assignUrl($fixture['item']), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);

        $this->assertSame(1, $this->auditLogsFor($itemUuid)->count());
    }

    public function test_unknown_technician_maps_to_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => UuidBinary::generate(),
        ], $this->bearer($admin['access_token']))->assertStatus(404);
    }

    public function test_malformed_booking_item_uuid_returns_404_for_assign(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $technician = $this->createTechnician();

        $this->postJson('/api/v1/admin/booking-items/not-a-uuid/assign-technician', [
            'technician_uuid' => $technician,
        ], $this->bearer($admin['access_token']))->assertStatus(404);
    }

    public function test_assign_request_requires_a_syntactically_valid_technician_uuid(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => 'not-a-uuid',
        ], $this->bearer($admin['access_token']))->assertStatus(422);
    }

    // 25. Admin can reassign.
    public function test_admin_can_reassign_to_a_new_technician(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $original = $this->createEligibleTechnician($fixture['specialization_id']);
        $replacement = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->postJson($this->assignUrl($fixture['item']), ['technician_uuid' => $original['uuid']], $this->bearer($admin['access_token']))->assertStatus(201);

        $response = $this->postJson($this->reassignUrl($fixture['item']), [
            'technician_uuid' => $replacement['uuid'],
            'release_reason' => 'Original technician unavailable.',
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['assignment' => ['technician' => ['uuid' => $replacement['uuid']]]],
        ]);
    }

    // 26 & 27. The old assignment is preserved (released, not deleted) and no longer active.
    public function test_reassignment_releases_the_old_assignment_without_deleting_it(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $original = $this->createEligibleTechnician($fixture['specialization_id']);
        $replacement = $this->createEligibleTechnician($fixture['specialization_id']);

        $originalResponse = $this->postJson($this->assignUrl($fixture['item']), ['technician_uuid' => $original['uuid']], $this->bearer($admin['access_token']))->assertStatus(201);
        $originalAssignmentUuid = $originalResponse->json('data.assignment.uuid');

        $this->postJson($this->reassignUrl($fixture['item']), [
            'technician_uuid' => $replacement['uuid'],
            'release_reason' => 'Reassigned for QA.',
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $originalRow = DB::table('technician_assignments')->where('id', UuidBinary::toBinary($originalAssignmentUuid))->first();
        $this->assertNotNull($originalRow, 'The original assignment row must still exist.');
        $this->assertNotNull($originalRow->released_at);
        $this->assertSame('Reassigned for QA.', $originalRow->release_reason);

        $active = DB::table('technician_assignments')
            ->where('booking_item_id', $fixture['item']->id)
            ->whereNull('released_at')
            ->where('is_primary', 1)
            ->first();
        $this->assertSame(UuidBinary::toBinary($replacement['uuid']), $active->technician_id);
        $this->assertSame(2, DB::table('technician_assignments')->where('booking_item_id', $fixture['item']->id)->count());
    }

    public function test_reassign_requires_a_release_reason(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $original = $this->createEligibleTechnician($fixture['specialization_id']);
        $replacement = $this->createEligibleTechnician($fixture['specialization_id']);
        $this->postJson($this->assignUrl($fixture['item']), ['technician_uuid' => $original['uuid']], $this->bearer($admin['access_token']))->assertStatus(201);

        $this->postJson($this->reassignUrl($fixture['item']), [
            'technician_uuid' => $replacement['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(422);
    }

    // 29. Reassign retry is safe (idempotent when reassigning to the already-active technician).
    public function test_reassign_retry_to_the_same_technician_is_idempotent(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $original = $this->createEligibleTechnician($fixture['specialization_id']);
        $replacement = $this->createEligibleTechnician($fixture['specialization_id']);
        $this->postJson($this->assignUrl($fixture['item']), ['technician_uuid' => $original['uuid']], $this->bearer($admin['access_token']))->assertStatus(201);

        $this->postJson($this->reassignUrl($fixture['item']), [
            'technician_uuid' => $replacement['uuid'],
            'release_reason' => 'First reassignment.',
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $retry = $this->postJson($this->reassignUrl($fixture['item']), [
            'technician_uuid' => $replacement['uuid'],
            'release_reason' => 'Retried reassignment.',
        ], $this->bearer($admin['access_token']));

        $retry->assertStatus(200);
        $this->assertSame(2, DB::table('technician_assignments')->where('booking_item_id', $fixture['item']->id)->count());
    }

    // 30. Audit behavior: reassignment writes exactly one TECHNICIAN_REASSIGNED row.
    public function test_reassignment_writes_an_audit_row(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $original = $this->createEligibleTechnician($fixture['specialization_id']);
        $replacement = $this->createEligibleTechnician($fixture['specialization_id']);
        $itemUuid = UuidBinary::toString($fixture['item']->id);
        $this->postJson($this->assignUrl($fixture['item']), ['technician_uuid' => $original['uuid']], $this->bearer($admin['access_token']))->assertStatus(201);

        $this->postJson($this->reassignUrl($fixture['item']), [
            'technician_uuid' => $replacement['uuid'],
            'release_reason' => 'Audit QA.',
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $logs = $this->auditLogsFor($itemUuid);
        $this->assertSame(1, $logs->where('action_code', 'TECHNICIAN_REASSIGNED')->count());
    }

    public function test_customer_cannot_reassign(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $replacement = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->postJson($this->reassignUrl($fixture['item']), [
            'technician_uuid' => $replacement['uuid'],
            'release_reason' => 'Should be rejected.',
        ], $this->bearer($fixture['customer']['access_token']))->assertStatus(401);
    }

    // Role removed after login blocks further assignment operations.
    public function test_admin_role_removed_after_login_blocks_assignment(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        DB::table('user_roles')
            ->where('user_id', UuidBinary::toBinary($admin['user_uuid']))
            ->delete();

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(401);
    }

    public function test_inactive_admin_account_blocks_assignment(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        DB::table('users')
            ->where('id', UuidBinary::toBinary($admin['user_uuid']))
            ->update(['account_status_id' => (int) DB::table('user_account_statuses')->where('code', 'DEACTIVATED')->value('id')]);

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(401);
    }
}
