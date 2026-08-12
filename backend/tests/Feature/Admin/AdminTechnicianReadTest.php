<?php

namespace Tests\Feature\Admin;

use App\Actions\Technician\AssignTechnicianToBookingItemAction;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\TestCase;

class AdminTechnicianReadTest extends TestCase
{
    use CreatesAdminFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    // 11. Admin can list Technicians.
    public function test_admin_can_list_technicians(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $technicianUuid = $this->createTechnician();

        $response = $this->getJson('/api/v1/admin/technicians', $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $uuids = collect($response->json('data.technicians'))->pluck('uuid')->all();
        $this->assertContains($technicianUuid, $uuids);
        $this->assertArrayHasKey('pagination', $response->json('data'));
    }

    // 12. Customer cannot list Technicians.
    public function test_customer_cannot_list_technicians(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->getJson('/api/v1/admin/technicians', $this->bearer($customer['access_token']))
            ->assertStatus(401);
    }

    public function test_status_filter_scopes_technician_list(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $available = $this->createTechnician(['status_id' => $this->technicianStatusId('AVAILABLE')]);
        $inactive = $this->createTechnician(['status_id' => $this->technicianStatusId('INACTIVE')]);

        $response = $this->getJson('/api/v1/admin/technicians?status=AVAILABLE', $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $uuids = collect($response->json('data.technicians'))->pluck('uuid')->all();
        $this->assertContains($available, $uuids);
        $this->assertNotContains($inactive, $uuids);
    }

    public function test_specialization_filter_scopes_technician_list(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $specializationId = $this->createSpecialization();
        $matching = $this->createEligibleTechnician($specializationId);
        $nonMatching = $this->createTechnician();

        $specializationCode = DB::table('specializations')->where('id', $specializationId)->value('code');

        $response = $this->getJson('/api/v1/admin/technicians?specialization='.$specializationCode, $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $uuids = collect($response->json('data.technicians'))->pluck('uuid')->all();
        $this->assertContains($matching['uuid'], $uuids);
        $this->assertNotContains($nonMatching, $uuids);
    }

    // 13. Status/specialization fields are safe - no internal numeric ids, no secrets.
    public function test_technician_list_response_shape_is_safe(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $specializationId = $this->createSpecialization();
        $this->createEligibleTechnician($specializationId);

        $response = $this->getJson('/api/v1/admin/technicians', $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $technician = collect($response->json('data.technicians'))->first();
        $this->assertSame(36, strlen($technician['uuid']));
        $this->assertIsString($technician['status']);
        $this->assertArrayNotHasKey('status_id', $technician);
        $this->assertArrayNotHasKey('id', $technician);
        foreach ($technician['specializations'] as $specialization) {
            $this->assertArrayHasKey('code', $specialization);
            $this->assertArrayNotHasKey('specialization_id', $specialization);
        }
    }

    // 14. Candidate filtering matches the Booking Item's actual service specialization.
    public function test_technician_candidates_match_the_booking_items_service_specialization(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $eligible = $this->createEligibleTechnician($fixture['specialization_id']);
        $otherSpecializationId = $this->createSpecialization();
        $ineligible = $this->createEligibleTechnician($otherSpecializationId);

        $response = $this->getJson('/api/v1/admin/booking-items/'.UuidBinary::toString($fixture['item']->id).'/technician-candidates', $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $candidateUuids = collect($response->json('data.candidates'))->pluck('uuid')->all();
        $this->assertContains($eligible['uuid'], $candidateUuids);
        $this->assertNotContains($ineligible['uuid'], $candidateUuids);
        $this->assertTrue($response->json('data.requirement_configured'));
    }

    public function test_candidates_for_unconfigured_service_returns_empty_with_flag(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem(['with_specialization' => false]);

        $response = $this->getJson('/api/v1/admin/booking-items/'.UuidBinary::toString($fixture['item']->id).'/technician-candidates', $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $this->assertFalse($response->json('data.requirement_configured'));
        $this->assertSame([], $response->json('data.candidates'));
    }

    public function test_customer_cannot_list_technician_candidates(): void
    {
        $fixture = $this->bookingWithAssignableItem();

        $this->getJson('/api/v1/admin/booking-items/'.UuidBinary::toString($fixture['item']->id).'/technician-candidates', $this->bearer($fixture['customer']['access_token']))
            ->assertStatus(401);
    }

    public function test_malformed_booking_item_uuid_returns_404_for_candidates(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/booking-items/not-a-uuid/technician-candidates', $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    // 15. The candidate list is advisory only - it never bypasses the real
    // assign() Action's own authoritative double-booking check.
    public function test_candidate_list_never_bypasses_final_assignment_validation(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $slot = ['starts_at' => now()->addDays(3)->setTime(10, 0), 'ends_at' => now()->addDays(3)->setTime(12, 0)];
        $fixtureA = $this->bookingWithAssignableItem(['slot' => $slot]);
        $technician = $this->createEligibleTechnician($fixtureA['specialization_id']);

        // Occupy the technician on a different, overlapping Booking first.
        $this->action(AssignTechnicianToBookingItemAction::class)
            ->assign(UuidBinary::toString($fixtureA['item']->id), $technician['uuid'], $admin['user_uuid']);

        $overlappingSlot = ['starts_at' => now()->addDays(3)->setTime(11, 0), 'ends_at' => now()->addDays(3)->setTime(13, 0)];
        $fixtureB = $this->bookingWithAssignableItem([
            'specialization_id' => $fixtureA['specialization_id'],
            'slot' => $overlappingSlot,
        ]);

        // The candidate list still surfaces this technician (they hold the
        // specialization) - the endpoint is advisory, not a reservation.
        $candidates = $this->getJson('/api/v1/admin/booking-items/'.UuidBinary::toString($fixtureB['item']->id).'/technician-candidates', $this->bearer($admin['access_token']))
            ->assertStatus(200);
        $candidateUuids = collect($candidates->json('data.candidates'))->pluck('uuid')->all();
        $this->assertContains($technician['uuid'], $candidateUuids);

        // But actually assigning them is still rejected - the real Action re-validates.
        $this->postJson('/api/v1/admin/booking-items/'.UuidBinary::toString($fixtureB['item']->id).'/assign-technician', [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(409);
    }

    private function action(string $class)
    {
        return app($class);
    }
}
