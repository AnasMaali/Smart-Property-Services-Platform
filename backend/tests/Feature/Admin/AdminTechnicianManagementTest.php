<?php

namespace Tests\Feature\Admin;

use App\Actions\Technician\AssignTechnicianToBookingItemAction;
use App\Actions\Technician\CompleteTechnicianJobAction;
use App\Actions\Technician\StartTechnicianJobAction;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Technician Admin Management - covers create/edit/status/
 * specialization mutations, the completed/active job counters, and the
 * rating-attribution rule, on top of the read-only coverage already in
 * AdminTechnicianReadTest.
 */
class AdminTechnicianManagementTest extends TestCase
{
    use CreatesAdminFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    // -----------------------------------------------------------------
    // Create
    // -----------------------------------------------------------------

    public function test_admin_can_create_technician_inactive_by_default(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->postJson('/api/v1/admin/technicians', [
            'employee_code' => 'TECH_NEW_001',
            'full_name' => 'New Technician',
            'phone_number' => '+971501112233',
            'email' => 'new.technician@example.com',
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        $this->assertSame('INACTIVE', $response->json('data.technician.status'));
        $this->assertFalse($response->json('data.technician.is_assignable'));
        $this->assertSame(1, $this->auditCountFor('TECHNICIAN_CREATED', $response->json('data.technician.uuid')));
    }

    public function test_create_technician_rejects_duplicate_phone_number(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->createTechnician(['phone_number' => '+971500000001']);

        $this->postJson('/api/v1/admin/technicians', [
            'employee_code' => 'TECH_DUP_001',
            'full_name' => 'Duplicate Phone',
            'phone_number' => '+971500000001',
        ], $this->bearer($admin['access_token']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);
    }

    public function test_view_only_capability_cannot_create_technician(): void
    {
        $this->denyTechniciansManageCapability();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->postJson('/api/v1/admin/technicians', [
            'employee_code' => 'TECH_DENY_001',
            'full_name' => 'Denied',
            'phone_number' => '+971500000099',
        ], $this->bearer($admin['access_token']))->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // Update
    // -----------------------------------------------------------------

    public function test_admin_can_update_technician_profile_fields(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $technicianUuid = $this->createTechnician(['full_name' => 'Old Name']);

        $response = $this->patchJson('/api/v1/admin/technicians/'.$technicianUuid, [
            'full_name' => 'New Name',
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $this->assertSame('New Name', $response->json('data.technician.full_name'));
        $this->assertSame(1, $this->auditCountFor('TECHNICIAN_UPDATED', $technicianUuid));
    }

    public function test_update_technician_rejects_duplicate_phone_number(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->createTechnician(['phone_number' => '+971500000002']);
        $technicianUuid = $this->createTechnician(['phone_number' => '+971500000003']);

        $this->patchJson('/api/v1/admin/technicians/'.$technicianUuid, [
            'phone_number' => '+971500000002',
        ], $this->bearer($admin['access_token']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);
    }

    // -----------------------------------------------------------------
    // Status
    // -----------------------------------------------------------------

    public function test_admin_can_activate_and_deactivate_technician_with_no_active_jobs(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $technicianUuid = $this->createTechnician(['status_id' => $this->technicianStatusId('INACTIVE')]);

        $this->postJson('/api/v1/admin/technicians/'.$technicianUuid.'/status', ['status' => 'AVAILABLE'], $this->bearer($admin['access_token']))
            ->assertStatus(200)
            ->assertJsonPath('data.technician.status', 'AVAILABLE');

        $this->postJson('/api/v1/admin/technicians/'.$technicianUuid.'/status', ['status' => 'INACTIVE'], $this->bearer($admin['access_token']))
            ->assertStatus(200)
            ->assertJsonPath('data.technician.status', 'INACTIVE');

        $this->assertSame(2, $this->auditCountFor('TECHNICIAN_STATUS_CHANGED', $technicianUuid));
    }

    public function test_setting_the_same_status_is_idempotent(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $technicianUuid = $this->createTechnician(['status_id' => $this->technicianStatusId('AVAILABLE')]);

        $this->postJson('/api/v1/admin/technicians/'.$technicianUuid.'/status', ['status' => 'AVAILABLE'], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $this->assertSame(0, $this->auditCountFor('TECHNICIAN_STATUS_CHANGED', $technicianUuid));
    }

    // 31. Deactivating a technician with an active/in-progress assignment is
    // rejected with a deterministic 409; it never orphans the job.
    public function test_deactivation_is_rejected_when_technician_has_an_active_job(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->action(AssignTechnicianToBookingItemAction::class)
            ->assign(UuidBinary::toString($fixture['item']->id), $technician['uuid'], $admin['user_uuid']);

        $this->postJson('/api/v1/admin/technicians/'.$technician['uuid'].'/status', ['status' => 'INACTIVE'], $this->bearer($admin['access_token']))
            ->assertStatus(409);

        $this->assertSame('AVAILABLE', DB::table('technicians')
            ->join('technician_statuses', 'technician_statuses.id', '=', 'technicians.status_id')
            ->where('technicians.id', UuidBinary::toBinary($technician['uuid']))
            ->value('technician_statuses.code'));
    }

    public function test_moving_to_busy_is_never_blocked_by_an_active_job(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->action(AssignTechnicianToBookingItemAction::class)
            ->assign(UuidBinary::toString($fixture['item']->id), $technician['uuid'], $admin['user_uuid']);

        $this->postJson('/api/v1/admin/technicians/'.$technician['uuid'].'/status', ['status' => 'BUSY'], $this->bearer($admin['access_token']))
            ->assertStatus(200)
            ->assertJsonPath('data.technician.status', 'BUSY');
    }

    // -----------------------------------------------------------------
    // Specializations
    // -----------------------------------------------------------------

    public function test_admin_can_add_and_deactivate_a_technician_specialization(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $technicianUuid = $this->createTechnician();
        $specializationId = $this->createSpecialization();

        $response = $this->postJson('/api/v1/admin/technicians/'.$technicianUuid.'/specializations', [
            'specialization_id' => $specializationId,
            'is_primary' => true,
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $this->assertTrue(collect($response->json('data.technician.specializations'))->contains(fn ($s) => $s['id'] === $specializationId && $s['is_primary']));

        $this->postJson('/api/v1/admin/technicians/'.$technicianUuid.'/specializations', [
            'specialization_id' => $specializationId,
            'is_active' => false,
        ], $this->bearer($admin['access_token']))
            ->assertStatus(200)
            ->assertJsonPath('data.technician.specializations', []);
    }

    public function test_a_second_primary_specialization_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $technicianUuid = $this->createTechnician();
        $first = $this->createSpecialization();
        $second = $this->createSpecialization();

        $this->postJson('/api/v1/admin/technicians/'.$technicianUuid.'/specializations', [
            'specialization_id' => $first, 'is_primary' => true,
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $this->postJson('/api/v1/admin/technicians/'.$technicianUuid.'/specializations', [
            'specialization_id' => $second, 'is_primary' => true,
        ], $this->bearer($admin['access_token']))->assertStatus(409);
    }

    // -----------------------------------------------------------------
    // Performance metrics - BLUE V1 Technician Admin Management section 29.
    // -----------------------------------------------------------------

    public function test_completed_and_active_job_counts_match_the_technician_a_fixture(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $specializationId = $this->createSpecialization();
        $technicianA = $this->createEligibleTechnician($specializationId);
        $technicianB = $this->createEligibleTechnician($specializationId);

        // Distinct, non-overlapping appointment slots - a completed
        // assignment is never released_at, so it still occupies the
        // Technician's calendar for App\Support\Technician\
        // TechnicianOverlapChecker's purposes; each Booking below needs its
        // own slot or the second/third assign() would be rejected as
        // TECHNICIAN_DOUBLE_BOOKED.
        $slotFor = fn (int $daysFromNow) => [
            'starts_at' => now()->addDays($daysFromNow)->setTime(9, 0),
            'ends_at' => now()->addDays($daysFromNow)->setTime(11, 0),
        ];

        // Booking 1: completed by A.
        $booking1 = $this->bookingWithAssignableItem(['specialization_id' => $specializationId, 'slot' => $slotFor(1)]);
        $assignAction = $this->action(AssignTechnicianToBookingItemAction::class);
        $assignAction->assign(UuidBinary::toString($booking1['item']->id), $technicianA['uuid'], $admin['user_uuid']);
        $this->action(StartTechnicianJobAction::class)->start(UuidBinary::toString($booking1['item']->id), $technicianA['uuid'], $admin['user_uuid']);
        $this->action(CompleteTechnicianJobAction::class)->complete(UuidBinary::toString($booking1['item']->id), $technicianA['uuid'], $admin['user_uuid']);

        // Booking 2: currently assigned to A (active, not started).
        $booking2 = $this->bookingWithAssignableItem(['specialization_id' => $specializationId, 'slot' => $slotFor(2)]);
        $assignAction->assign(UuidBinary::toString($booking2['item']->id), $technicianA['uuid'], $admin['user_uuid']);

        // Booking 3: assigned to A, then reassigned to B before completion.
        $booking3 = $this->bookingWithAssignableItem(['specialization_id' => $specializationId, 'slot' => $slotFor(3)]);
        $assignAction->assign(UuidBinary::toString($booking3['item']->id), $technicianA['uuid'], $admin['user_uuid']);
        $assignAction->reassign(UuidBinary::toString($booking3['item']->id), $technicianB['uuid'], $admin['user_uuid'], 'Technician A unavailable.');
        $this->action(StartTechnicianJobAction::class)->start(UuidBinary::toString($booking3['item']->id), $technicianB['uuid'], $admin['user_uuid']);
        $this->action(CompleteTechnicianJobAction::class)->complete(UuidBinary::toString($booking3['item']->id), $technicianB['uuid'], $admin['user_uuid']);

        $response = $this->getJson('/api/v1/admin/technicians/'.$technicianA['uuid'], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $performance = $response->json('data.technician.performance');
        $this->assertSame(1, $performance['completed_jobs']);
        $this->assertSame(1, $performance['active_jobs']);

        // Booking 3 is visible in A's history, but never credited as completed.
        $jobs = $this->getJson('/api/v1/admin/technicians/'.$technicianA['uuid'].'/jobs', $this->bearer($admin['access_token']))
            ->assertStatus(200)
            ->json('data.jobs');

        $booking3Job = collect($jobs)->firstWhere('booking_uuid', UuidBinary::toString($booking3['booking']->id));
        $this->assertNotNull($booking3Job);
        $this->assertNotNull($booking3Job['released_at']);
        $this->assertFalse($booking3Job['credited_as_completed']);

        // B is credited for Booking 3's completion.
        $performanceB = $this->getJson('/api/v1/admin/technicians/'.$technicianB['uuid'], $this->bearer($admin['access_token']))
            ->json('data.technician.performance');
        $this->assertSame(1, $performanceB['completed_jobs']);
    }

    // -----------------------------------------------------------------
    // Ratings - BLUE V1 Technician Admin Management section 13/30.
    // -----------------------------------------------------------------

    public function test_average_rating_only_counts_bookings_the_technician_solely_worked(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $specializationId = $this->createSpecialization();
        $technician = $this->createEligibleTechnician($specializationId);
        $other = $this->createEligibleTechnician($specializationId);

        // Distinct, non-overlapping slots - see the same note in
        // test_completed_and_active_job_counts_match_the_technician_a_fixture.
        $slotFor = fn (int $daysFromNow) => [
            'starts_at' => now()->addDays($daysFromNow)->setTime(9, 0),
            'ends_at' => now()->addDays($daysFromNow)->setTime(11, 0),
        ];

        $day = 0;

        foreach ([5, 4, 3] as $ratingValue) {
            $day++;
            $booking = $this->bookingWithAssignableItem(['specialization_id' => $specializationId, 'slot' => $slotFor($day)]);
            $this->action(AssignTechnicianToBookingItemAction::class)
                ->assign(UuidBinary::toString($booking['item']->id), $technician['uuid'], $admin['user_uuid']);

            DB::table('ratings')->insert([
                'booking_id' => $booking['booking']->id,
                'rating_value' => $ratingValue,
                'comment' => null,
                'created_at' => now(),
            ]);
        }

        // A shared booking - a second, different technician also ever
        // touched it (assigned, then reassigned away) - must never count
        // toward the average, even though $technician is the one currently
        // active on it.
        $sharedBooking = $this->bookingWithAssignableItem(['specialization_id' => $specializationId, 'slot' => $slotFor(++$day)]);
        $assignAction = $this->action(AssignTechnicianToBookingItemAction::class);
        $assignAction->assign(UuidBinary::toString($sharedBooking['item']->id), $other['uuid'], $admin['user_uuid']);
        $assignAction->reassign(UuidBinary::toString($sharedBooking['item']->id), $technician['uuid'], $admin['user_uuid'], 'Reassigned for QA fixture.');
        DB::table('ratings')->insert([
            'booking_id' => $sharedBooking['booking']->id,
            'rating_value' => 1,
            'comment' => null,
            'created_at' => now(),
        ]);

        $performance = $this->getJson('/api/v1/admin/technicians/'.$technician['uuid'], $this->bearer($admin['access_token']))
            ->json('data.technician.performance');

        $this->assertSame(3, $performance['rating_count']);
        $this->assertEqualsWithDelta(4.0, $performance['average_rating'], 0.001);

        $ratings = $this->getJson('/api/v1/admin/technicians/'.$technician['uuid'].'/ratings', $this->bearer($admin['access_token']))
            ->json('data.ratings');
        $this->assertCount(4, $ratings);
        $shared = collect($ratings)->firstWhere('booking_uuid', UuidBinary::toString($sharedBooking['booking']->id));
        $this->assertFalse($shared['is_exclusive']);
    }

    public function test_technician_with_no_ratings_reports_null_average(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $technicianUuid = $this->createTechnician();

        $performance = $this->getJson('/api/v1/admin/technicians/'.$technicianUuid, $this->bearer($admin['access_token']))
            ->json('data.technician.performance');

        $this->assertNull($performance['average_rating']);
        $this->assertSame(0, $performance['rating_count']);
    }

    // -----------------------------------------------------------------
    // History immutability - BLUE V1 Technician Admin Management section 36.
    // -----------------------------------------------------------------

    public function test_editing_a_technician_never_rewrites_historical_assignment_data(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id'], ['full_name' => 'Original Name']);

        $this->action(AssignTechnicianToBookingItemAction::class)
            ->assign(UuidBinary::toString($fixture['item']->id), $technician['uuid'], $admin['user_uuid']);

        $assignmentBefore = DB::table('technician_assignments')->where('technician_id', UuidBinary::toBinary($technician['uuid']))->first();

        $this->patchJson('/api/v1/admin/technicians/'.$technician['uuid'], ['full_name' => 'Renamed Technician'], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $assignmentAfter = DB::table('technician_assignments')->where('id', $assignmentBefore->id)->first();
        $this->assertEquals($assignmentBefore, $assignmentAfter);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function denyTechniciansManageCapability(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'technicians.manage')->value('id');

        DB::table('admin_role_permissions')->where('role_id', $adminRoleId)->where('permission_id', $permissionId)->delete();
    }

    private function auditCountFor(string $actionCode, string $entityIdentifier): int
    {
        return DB::table('admin_audit_logs')
            ->where('action_code', $actionCode)
            ->where('entity_identifier', $entityIdentifier)
            ->count();
    }

    private function action(string $class)
    {
        return app($class);
    }
}
