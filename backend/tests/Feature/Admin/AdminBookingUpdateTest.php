<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B15 - Admin Edit Booking (App\Actions\Admin\Booking\
 * AdminUpdateBookingAction / UpdateAdminBookingController). Covers only the
 * operational visit/location fields this operation is allowed to touch -
 * every other Booking domain (status, items, pricing, payment, Contract
 * linkage, appointment slot, technician assignments) is asserted unchanged.
 */
class AdminBookingUpdateTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function patchBooking(string $accessToken, string $bookingUuid, array $payload): TestResponse
    {
        return $this->patchJson(
            '/api/v1/admin/bookings/'.$bookingUuid,
            $payload,
            $this->bearer($accessToken)
        );
    }

    private function locationRow(string $bookingIdBinary): ?object
    {
        return DB::table('booking_locations')->where('booking_id', $bookingIdBinary)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function validEditPayload(array $overrides = []): array
    {
        return array_merge([
            'street_name' => 'Al Wasl Road',
            'address_line' => 'Updated address line for QA',
            'building_name_or_number' => 'Tower B',
            'floor_number' => '7',
            'unit_number' => '702',
            'nearby_landmark' => 'Near QA Mall',
            'additional_location_notes' => 'Updated QA note.',
            'visit_contact_phone' => '+971501112233',
        ], $overrides);
    }

    private function denyBookingsManageCapability(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'bookings.manage')->value('id');

        DB::table('admin_role_permissions')->where('role_id', $adminRoleId)->where('permission_id', $permissionId)->delete();
    }

    private function auditRow(string $actionCode): ?object
    {
        return DB::table('admin_audit_logs')->where('action_code', $actionCode)->orderByDesc('created_at')->first();
    }

    private function auditCount(string $actionCode): int
    {
        return DB::table('admin_audit_logs')->where('action_code', $actionCode)->count();
    }

    /**
     * Drives a real technician assignment so the parent Booking is
     * synchronized to ASSIGNED (App\Actions\Booking\
     * SyncBookingStatusFromItemsAction), exactly as the real Admin
     * technician workspace does - never forced directly via SQL.
     */
    private function assignTechnician(array $admin, array $fixture): void
    {
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->postJson(
            '/api/v1/admin/booking-items/'.UuidBinary::toString($fixture['item']->id).'/assign-technician',
            ['technician_uuid' => $technician['uuid']],
            $this->bearer($admin['access_token'])
        )->assertStatus(201);
    }

    private function startWork(array $admin, array $fixture): void
    {
        $this->assignTechnician($admin, $fixture);
        $item = DB::table('booking_items')->where('id', $fixture['item']->id)->first();
        $technicianUuid = UuidBinary::toString(
            DB::table('technician_assignments')->where('booking_item_id', $item->id)->whereNull('released_at')->value('technician_id')
        );

        $this->postJson(
            '/api/v1/admin/booking-items/'.UuidBinary::toString($fixture['item']->id).'/start-work',
            ['technician_uuid' => $technicianUuid],
            $this->bearer($admin['access_token'])
        )->assertStatus(200);
    }

    private function completeWork(array $admin, array $fixture): void
    {
        $this->startWork($admin, $fixture);
        $technicianUuid = UuidBinary::toString(
            DB::table('technician_assignments')->where('booking_item_id', $fixture['item']->id)->whereNull('released_at')->value('technician_id')
        );

        $this->postJson(
            '/api/v1/admin/booking-items/'.UuidBinary::toString($fixture['item']->id).'/complete-work',
            ['technician_uuid' => $technicianUuid],
            $this->bearer($admin['access_token'])
        )->assertStatus(200);
    }

    // -----------------------------------------------------------------
    // 1-3. Authentication / authorization
    // -----------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->patchJson('/api/v1/admin/bookings/'.UuidBinary::generate(), $this->validEditPayload())
            ->assertStatus(401);
    }

    public function test_authenticated_admin_without_bookings_manage_capability_is_rejected(): void
    {
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);

        $this->denyBookingsManageCapability();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->patchBooking($admin['access_token'], UuidBinary::toString($booking->id), $this->validEditPayload())
            ->assertStatus(403);

        $this->assertSame(0, $this->auditCount('BOOKING_UPDATED'));
    }

    public function test_super_admin_is_allowed_via_the_existing_authorization_override(): void
    {
        $this->denyBookingsManageCapability();
        $admin = $this->createAndLoginAdmin(['SUPER_ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);

        $this->patchBooking($admin['access_token'], UuidBinary::toString($booking->id), $this->validEditPayload(['street_name' => 'Super Admin Street']))
            ->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // -----------------------------------------------------------------
    // 4-5. Not found
    // -----------------------------------------------------------------

    public function test_malformed_booking_uuid_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->patchBooking($admin['access_token'], 'not-a-uuid', $this->validEditPayload())
            ->assertStatus(404);
    }

    public function test_unknown_booking_uuid_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->patchBooking($admin['access_token'], UuidBinary::generate(), $this->validEditPayload())
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // 6-8. Editable in every non-terminal status
    // -----------------------------------------------------------------

    public function test_paid_booking_can_update_every_supported_operational_field(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);
        $payload = $this->validEditPayload();

        $this->patchBooking($admin['access_token'], UuidBinary::toString($booking->id), $payload)
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $location = $this->locationRow($booking->id);

        foreach ($payload as $field => $value) {
            $this->assertSame($value, $location->{$field}, "Field {$field} was not updated.");
        }
    }

    public function test_assigned_booking_can_be_edited(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();

        $this->assignTechnician($admin, $fixture);

        $booking = DB::table('bookings')->where('id', $fixture['booking']->id)->first();
        $this->assertSame('ASSIGNED', DB::table('booking_statuses')->where('id', $booking->status_id)->value('code'));

        $this->patchBooking($admin['access_token'], UuidBinary::toString($booking->id), $this->validEditPayload(['floor_number' => '9']))
            ->assertStatus(200);

        $this->assertSame('9', $this->locationRow($booking->id)->floor_number);
    }

    public function test_in_progress_booking_can_be_edited(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();

        $this->startWork($admin, $fixture);

        $booking = DB::table('bookings')->where('id', $fixture['booking']->id)->first();
        $this->assertSame('IN_PROGRESS', DB::table('booking_statuses')->where('id', $booking->status_id)->value('code'));

        $this->patchBooking($admin['access_token'], UuidBinary::toString($booking->id), $this->validEditPayload(['floor_number' => '10']))
            ->assertStatus(200);

        $this->assertSame('10', $this->locationRow($booking->id)->floor_number);
    }

    // -----------------------------------------------------------------
    // 9-12. Partial update / normalization / validation
    // -----------------------------------------------------------------

    public function test_partial_update_only_modifies_supplied_fields(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);
        $before = $this->locationRow($booking->id);

        $this->patchBooking($admin['access_token'], UuidBinary::toString($booking->id), ['floor_number' => 'PH2'])
            ->assertStatus(200);

        $after = $this->locationRow($booking->id);

        $this->assertSame('PH2', $after->floor_number);
        $this->assertSame($before->street_name, $after->street_name);
        $this->assertSame($before->address_line, $after->address_line);
        $this->assertSame($before->building_name_or_number, $after->building_name_or_number);
        $this->assertSame($before->unit_number, $after->unit_number);
        $this->assertSame($before->nearby_landmark, $after->nearby_landmark);
        $this->assertSame($before->additional_location_notes, $after->additional_location_notes);
        $this->assertSame($before->visit_contact_phone, $after->visit_contact_phone);
    }

    public function test_nullable_fields_can_be_cleared(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);

        $this->assertNotNull($this->locationRow($booking->id)->floor_number);

        $this->patchBooking($admin['access_token'], UuidBinary::toString($booking->id), [
            'floor_number' => '',
            'unit_number' => '',
            'nearby_landmark' => '',
            'additional_location_notes' => '',
        ])->assertStatus(200);

        $after = $this->locationRow($booking->id);
        $this->assertNull($after->floor_number);
        $this->assertNull($after->unit_number);
        $this->assertNull($after->nearby_landmark);
        $this->assertNull($after->additional_location_notes);
    }

    public function test_required_fields_reject_null(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);
        $before = $this->locationRow($booking->id);

        $this->patchBooking($admin['access_token'], UuidBinary::toString($booking->id), ['street_name' => null])
            ->assertStatus(422);

        $this->assertSame($before->street_name, $this->locationRow($booking->id)->street_name);
        $this->assertSame(0, $this->auditCount('BOOKING_UPDATED'));
    }

    public function test_required_fields_reject_blank_or_whitespace_only_values(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);
        $before = $this->locationRow($booking->id);

        // Passes shape validation (min length satisfied by raw whitespace)
        // but must still be rejected once trimmed - Action-level guard.
        $this->patchBooking($admin['access_token'], UuidBinary::toString($booking->id), ['street_name' => '  '])
            ->assertStatus(422);

        $this->patchBooking($admin['access_token'], UuidBinary::toString($booking->id), ['visit_contact_phone' => '        '])
            ->assertStatus(422);

        $this->patchBooking($admin['access_token'], UuidBinary::toString($booking->id), ['address_line' => '     '])
            ->assertStatus(422);

        $this->patchBooking($admin['access_token'], UuidBinary::toString($booking->id), ['building_name_or_number' => ' '])
            ->assertStatus(422);

        $after = $this->locationRow($booking->id);
        $this->assertSame($before->street_name, $after->street_name);
        $this->assertSame($before->visit_contact_phone, $after->visit_contact_phone);
        $this->assertSame($before->address_line, $after->address_line);
        $this->assertSame($before->building_name_or_number, $after->building_name_or_number);
        $this->assertSame(0, $this->auditCount('BOOKING_UPDATED'));
    }

    // -----------------------------------------------------------------
    // 13-14. Terminal Bookings are frozen
    // -----------------------------------------------------------------

    public function test_completed_booking_cannot_be_edited(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();

        $this->completeWork($admin, $fixture);

        $booking = DB::table('bookings')->where('id', $fixture['booking']->id)->first();
        $this->assertSame('COMPLETED', DB::table('booking_statuses')->where('id', $booking->status_id)->value('code'));
        $before = $this->locationRow($booking->id);

        $this->patchBooking($admin['access_token'], UuidBinary::toString($booking->id), $this->validEditPayload())
            ->assertStatus(409);

        $this->assertEquals($before, $this->locationRow($booking->id));
        $this->assertSame(0, $this->auditCount('BOOKING_UPDATED'));
    }

    public function test_cancelled_booking_cannot_be_edited(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);

        $this->postJson(
            '/api/v1/bookings/'.UuidBinary::toString($booking->id).'/cancel',
            [],
            ['Authorization' => 'Bearer '.$fixture['customer']['access_token']]
        )->assertStatus(200);

        $before = $this->locationRow($booking->id);

        $this->patchBooking($admin['access_token'], UuidBinary::toString($booking->id), $this->validEditPayload())
            ->assertStatus(409);

        $this->assertEquals($before, $this->locationRow($booking->id));
        $this->assertSame(0, $this->auditCount('BOOKING_UPDATED'));
    }

    // -----------------------------------------------------------------
    // 15-23. A successful edit never touches any other domain
    // -----------------------------------------------------------------

    public function test_successful_update_never_touches_any_unrelated_domain(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $this->assignTechnician($admin, $fixture);

        $bookingId = $fixture['booking']->id;
        $bookingBefore = DB::table('bookings')->where('id', $bookingId)->first();
        $itemsBefore = DB::table('booking_items')->where('booking_id', $bookingId)->orderBy('id')->get();
        $paymentBefore = DB::table('payment_attempts')->where('id', $bookingBefore->payment_attempt_id)->first();
        $assignmentsBefore = DB::table('technician_assignments')
            ->whereIn('booking_item_id', $itemsBefore->pluck('id'))
            ->orderBy('id')
            ->get();

        $this->patchBooking($admin['access_token'], UuidBinary::toString($bookingId), $this->validEditPayload())
            ->assertStatus(200);

        $bookingAfter = DB::table('bookings')->where('id', $bookingId)->first();
        $itemsAfter = DB::table('booking_items')->where('booking_id', $bookingId)->orderBy('id')->get();
        $paymentAfter = DB::table('payment_attempts')->where('id', $bookingAfter->payment_attempt_id)->first();
        $assignmentsAfter = DB::table('technician_assignments')
            ->whereIn('booking_item_id', $itemsAfter->pluck('id'))
            ->orderBy('id')
            ->get();

        $this->assertSame($bookingBefore->status_id, $bookingAfter->status_id);
        $this->assertSame($bookingBefore->appointment_slot_id, $bookingAfter->appointment_slot_id);
        $this->assertSame($bookingBefore->booking_source_id, $bookingAfter->booking_source_id);
        $this->assertSame($bookingBefore->cart_id, $bookingAfter->cart_id);
        $this->assertSame($bookingBefore->payment_attempt_id, $bookingAfter->payment_attempt_id);
        $this->assertSame($bookingBefore->service_contract_id, $bookingAfter->service_contract_id);
        $this->assertSame($bookingBefore->service_contract_item_id, $bookingAfter->service_contract_item_id);
        $this->assertSame($bookingBefore->booking_number, $bookingAfter->booking_number);

        $this->assertEquals($itemsBefore, $itemsAfter);
        $this->assertEquals($paymentBefore, $paymentAfter);
        $this->assertEquals($assignmentsBefore, $assignmentsAfter);
    }

    public function test_successful_update_never_touches_contract_linkage_or_entitlement(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $built = $this->activeContractWithItem();
        $slot = $this->createAppointmentSlot();

        $bookResponse = $this->bookContractService(
            $built['customer']['access_token'],
            UuidBinary::toString($built['contract']->id),
            UuidBinary::toString($built['item']->id),
            $slot['uuid']
        )->assertStatus(201);

        $bookingUuid = $bookResponse->json('data.booking.uuid');

        $before = $this->getJson('/api/v1/admin/bookings/'.$bookingUuid, $this->bearer($admin['access_token']))
            ->assertStatus(200)
            ->json('data.booking');

        $this->patchBooking($admin['access_token'], $bookingUuid, $this->validEditPayload(['street_name' => 'Contract Booking Street']))
            ->assertStatus(200);

        $after = $this->getJson('/api/v1/admin/bookings/'.$bookingUuid, $this->bearer($admin['access_token']))
            ->assertStatus(200)
            ->json('data.booking');

        $this->assertSame($before['contract'], $after['contract']);
        $this->assertNull($after['payment']);
        $this->assertSame('Contract Booking Street', $after['location']['street_name']);
    }

    // -----------------------------------------------------------------
    // 24-27. Audit behavior on a real mutation
    // -----------------------------------------------------------------

    public function test_successful_update_writes_exactly_one_correctly_scoped_audit_event(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);
        $bookingUuid = UuidBinary::toString($booking->id);

        $this->patchBooking($admin['access_token'], $bookingUuid, [
            'street_name' => 'Al Wasl Road',
            'floor_number' => '9',
        ])->assertStatus(200);

        $this->assertSame(1, $this->auditCount('BOOKING_UPDATED'));

        $audit = $this->auditRow('BOOKING_UPDATED');
        $this->assertSame('BOOKING', $audit->entity_type);
        $this->assertSame($bookingUuid, $audit->entity_identifier);
        $this->assertSame(1, (int) $audit->was_successful);

        $oldValues = json_decode($audit->old_values, true);
        $newValues = json_decode($audit->new_values, true);

        $this->assertSame(['street_name', 'floor_number'], array_keys($oldValues));
        $this->assertSame(['street_name', 'floor_number'], array_keys($newValues));
        $this->assertSame('Al Wasl Road', $newValues['street_name']);
        $this->assertSame('9', $newValues['floor_number']);
        $this->assertNotSame('Al Wasl Road', $oldValues['street_name']);
    }

    // -----------------------------------------------------------------
    // 28-30. No audit event for non-mutating outcomes
    // -----------------------------------------------------------------

    public function test_no_op_update_creates_no_audit_event(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);
        $location = $this->locationRow($booking->id);

        $this->patchBooking($admin['access_token'], UuidBinary::toString($booking->id), [
            'street_name' => $location->street_name,
            'address_line' => $location->address_line,
        ])
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSame(0, $this->auditCount('BOOKING_UPDATED'));
    }

    public function test_validation_failure_creates_no_audit_event(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);

        $this->patchBooking($admin['access_token'], UuidBinary::toString($booking->id), ['street_name' => 'A'])
            ->assertStatus(422);

        $this->assertSame(0, $this->auditCount('BOOKING_UPDATED'));
    }

    // terminal Booking rejection creates no audit event: covered by
    // test_completed_booking_cannot_be_edited / test_cancelled_booking_cannot_be_edited above.

    // -----------------------------------------------------------------
    // 31. GET reflects the authoritative updated location
    // -----------------------------------------------------------------

    public function test_admin_get_booking_reflects_the_updated_location_afterward(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);
        $bookingUuid = UuidBinary::toString($booking->id);
        $payload = $this->validEditPayload();

        $this->patchBooking($admin['access_token'], $bookingUuid, $payload)->assertStatus(200);

        $response = $this->getJson('/api/v1/admin/bookings/'.$bookingUuid, $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $location = $response->json('data.booking.location');

        foreach ($payload as $field => $value) {
            $this->assertSame($value, $location[$field], "Field {$field} was not reflected in GET.");
        }
    }
}
