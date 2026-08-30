<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B19 - Admin "Reschedule Booking" (App\Actions\Admin\Booking\
 * AdminRescheduleBookingAction). Reuses App\Support\Checkout\
 * AppointmentSlotAvailability's occupancy model and App\Support\Technician\
 * TechnicianOverlapChecker - not re-tested here beyond what this operation
 * needs; both are already covered by tests/Feature/Checkout/
 * AppointmentSlotsTest.php and tests/Feature/Technician/TechnicianAssignmentTest.php.
 */
class AdminBookingRescheduleTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    private function reschedule(?string $accessToken, string $bookingUuid, ?string $slotUuid, ?string $reason = 'Customer requested a different time.'): TestResponse
    {
        $payload = [];

        if ($slotUuid !== null) {
            $payload['appointment_slot_uuid'] = $slotUuid;
        }

        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        return $this->postJson(
            '/api/v1/admin/bookings/'.$bookingUuid.'/reschedule',
            $payload,
            $accessToken === null ? [] : $this->bearer($accessToken)
        );
    }

    private function denyCapability(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'bookings.reschedule')->value('id');
        DB::table('admin_role_permissions')->where('role_id', $adminRoleId)->where('permission_id', $permissionId)->delete();
    }

    private function auditCount(): int
    {
        return DB::table('admin_audit_logs')->where('action_code', 'BOOKING_RESCHEDULED')->count();
    }

    private function occupancy(string $slotUuidBinary): int
    {
        $now = now();

        return DB::table('appointment_holds')
            ->where('appointment_slot_id', $slotUuidBinary)
            ->whereNull('released_at')
            ->whereNull('superseded_at')
            ->where(function ($q) use ($now) {
                $q->whereNotNull('converted_at')->orWhere('expires_at', '>', $now);
            })
            ->count();
    }

    // -----------------------------------------------------------------
    // Auth / authz
    // -----------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->reschedule(null, UuidBinary::generate(), UuidBinary::generate())->assertStatus(401);
    }

    public function test_admin_without_capability_is_rejected(): void
    {
        $this->denyCapability();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->reschedule($admin['access_token'], UuidBinary::generate(), UuidBinary::generate())->assertStatus(403);
        $this->assertSame(0, $this->auditCount());
    }

    public function test_super_admin_is_allowed_via_the_existing_authorization_override(): void
    {
        $this->denyCapability();
        $admin = $this->createAndLoginAdmin(['SUPER_ADMIN']);
        $fixture = $this->successfulPayment();
        $bookingUuid = UuidBinary::toString($this->bookingRowForPayment($fixture['payment'])->id);
        $newSlot = $this->createAppointmentSlot();

        $this->reschedule($admin['access_token'], $bookingUuid, $newSlot['uuid'])->assertStatus(200);
    }

    // -----------------------------------------------------------------
    // Not found / validation
    // -----------------------------------------------------------------

    public function test_malformed_and_unknown_booking_uuid_return_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $slot = $this->createAppointmentSlot();

        $this->reschedule($admin['access_token'], 'not-a-uuid', $slot['uuid'])->assertStatus(404);
        $this->reschedule($admin['access_token'], UuidBinary::generate(), $slot['uuid'])->assertStatus(404);
    }

    public function test_malformed_and_unknown_slot_uuid_return_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $bookingUuid = UuidBinary::toString($this->bookingRowForPayment($fixture['payment'])->id);

        $this->reschedule($admin['access_token'], $bookingUuid, 'not-a-uuid')->assertStatus(404);
        $this->reschedule($admin['access_token'], $bookingUuid, UuidBinary::generate())->assertStatus(404);
        $this->assertSame(0, $this->auditCount());
    }

    public function test_reason_is_mandatory(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $bookingUuid = UuidBinary::toString($this->bookingRowForPayment($fixture['payment'])->id);
        $slot = $this->createAppointmentSlot();

        $this->reschedule($admin['access_token'], $bookingUuid, $slot['uuid'], null)->assertStatus(422);
        $this->reschedule($admin['access_token'], $bookingUuid, $slot['uuid'], '  ')->assertStatus(422);
        $this->assertSame(0, $this->auditCount());
    }

    // -----------------------------------------------------------------
    // Lifecycle eligibility
    // -----------------------------------------------------------------

    public function test_cancelled_and_completed_bookings_are_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $newSlot = $this->createAppointmentSlot();

        // CANCELLED
        $cancelledFixture = $this->successfulPayment();
        $cancelledUuid = UuidBinary::toString($this->bookingRowForPayment($cancelledFixture['payment'])->id);
        $this->postJson('/api/v1/bookings/'.$cancelledUuid.'/cancel', [], ['Authorization' => 'Bearer '.$cancelledFixture['customer']['access_token']])->assertStatus(200);
        $this->reschedule($admin['access_token'], $cancelledUuid, $newSlot['uuid'])->assertStatus(409);

        // COMPLETED
        $completedFixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($completedFixture['specialization_id']);
        $itemUuid = UuidBinary::toString($completedFixture['item']->id);
        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/assign-technician", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(201);
        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/start-work", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);
        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/complete-work", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);
        $completedUuid = UuidBinary::toString($completedFixture['booking']->id);
        $this->reschedule($admin['access_token'], $completedUuid, $newSlot['uuid'])->assertStatus(409);

        $this->assertSame(0, $this->auditCount());
    }

    public function test_in_progress_booking_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $itemUuid = UuidBinary::toString($fixture['item']->id);
        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/assign-technician", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(201);
        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/start-work", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);

        $newSlot = $this->createAppointmentSlot();
        $this->reschedule($admin['access_token'], UuidBinary::toString($fixture['booking']->id), $newSlot['uuid'])->assertStatus(409);
        $this->assertSame(0, $this->auditCount());
    }

    // -----------------------------------------------------------------
    // Same-slot no-op
    // -----------------------------------------------------------------

    public function test_same_slot_is_a_harmless_noop(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);

        $this->reschedule($admin['access_token'], UuidBinary::toString($booking->id), UuidBinary::toString($booking->appointment_slot_id))
            ->assertStatus(200);

        $this->assertSame(0, $this->auditCount());
    }

    // -----------------------------------------------------------------
    // Successful reschedule: capacity, history, audit, isolation
    // -----------------------------------------------------------------

    public function test_successful_reschedule_frees_old_slot_and_consumes_new_slot_capacity(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $newSlot = $this->createAppointmentSlot(['booking_capacity' => 1]);

        $fixture = $this->successfulPayment(['booking_capacity' => 1]);
        $booking = $this->bookingRowForPayment($fixture['payment']);
        $bookingUuid = UuidBinary::toString($booking->id);
        $oldSlot = ['uuid' => UuidBinary::toString($booking->appointment_slot_id)];

        $this->assertSame(1, $this->occupancy($booking->appointment_slot_id));
        $this->assertSame(0, $this->occupancy(UuidBinary::toBinary($newSlot['uuid'])));

        $bookingItemsBefore = DB::table('booking_items')->where('booking_id', $booking->id)->orderBy('id')->get();
        $paymentBefore = DB::table('payment_attempts')->where('id', $booking->payment_attempt_id)->first();

        $response = $this->reschedule($admin['access_token'], $bookingUuid, $newSlot['uuid'], 'Technician traffic delay - moved to next slot.')
            ->assertStatus(200);

        $this->assertSame($newSlot['uuid'], $response->json('data.booking.appointment_slot_uuid'));

        // Old slot freed, new slot consumed.
        $this->assertSame(0, $this->occupancy(UuidBinary::toBinary($oldSlot['uuid'])));
        $this->assertSame(1, $this->occupancy(UuidBinary::toBinary($newSlot['uuid'])));

        $bookingAfter = DB::table('bookings')->where('id', $booking->id)->first();
        $this->assertSame(UuidBinary::toBinary($newSlot['uuid']), $bookingAfter->appointment_slot_id);

        // Untouched invariants.
        $this->assertSame($booking->status_id, $bookingAfter->status_id);
        $this->assertEquals($bookingItemsBefore, DB::table('booking_items')->where('booking_id', $booking->id)->orderBy('id')->get());
        $paymentAfter = DB::table('payment_attempts')->where('id', $bookingAfter->payment_attempt_id)->first();
        $this->assertEquals($paymentBefore, $paymentAfter);

        // Audit exactly once, correct old/new values.
        $this->assertSame(1, $this->auditCount());
        $audit = DB::table('admin_audit_logs')->where('action_code', 'BOOKING_RESCHEDULED')->first();
        $this->assertSame('BOOKING', $audit->entity_type);
        $this->assertSame($bookingUuid, $audit->entity_identifier);
        $this->assertSame(['appointment_slot_uuid' => $oldSlot['uuid']], json_decode($audit->old_values, true));
        $newValues = json_decode($audit->new_values, true);
        $this->assertSame($newSlot['uuid'], $newValues['appointment_slot_uuid']);
        $this->assertSame('Technician traffic delay - moved to next slot.', $newValues['reason']);

        // GET reflects the new appointment.
        $get = $this->getJson('/api/v1/admin/bookings/'.$bookingUuid, $this->bearer($admin['access_token']))->assertStatus(200);
        $this->assertSame($newSlot['uuid'], $get->json('data.booking.appointment.slot.uuid'));
    }

    public function test_full_new_slot_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        // A capacity-1 slot, already fully occupied by a different Booking.
        $fullFixture = $this->successfulPayment(['booking_capacity' => 1]);
        $fullSlotUuid = UuidBinary::toString($this->bookingRowForPayment($fullFixture['payment'])->appointment_slot_id);

        $fixture = $this->successfulPayment();
        $bookingUuid = UuidBinary::toString($this->bookingRowForPayment($fixture['payment'])->id);

        $this->reschedule($admin['access_token'], $bookingUuid, $fullSlotUuid)->assertStatus(409);
        $this->assertSame(0, $this->auditCount());
    }

    // -----------------------------------------------------------------
    // Technician overlap
    // -----------------------------------------------------------------

    public function test_reschedule_that_would_double_book_an_assigned_technician_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $specializationId = $this->createSpecialization();

        $fixtureA = $this->bookingWithAssignableItem([
            'specialization_id' => $specializationId,
            'slot' => ['starts_at' => now()->addDays(20)->setTime(9, 0), 'ends_at' => now()->addDays(20)->setTime(11, 0)],
        ]);
        $technician = $this->createEligibleTechnician($specializationId);
        $this->postJson('/api/v1/admin/booking-items/'.UuidBinary::toString($fixtureA['item']->id).'/assign-technician', ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(201);

        $fixtureB = $this->bookingWithAssignableItem([
            'specialization_id' => $specializationId,
            'slot' => ['starts_at' => now()->addDays(21)->setTime(9, 0), 'ends_at' => now()->addDays(21)->setTime(11, 0)],
        ]);
        $this->postJson('/api/v1/admin/booking-items/'.UuidBinary::toString($fixtureB['item']->id).'/assign-technician', ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(201);

        // Overlaps slot A's period - must be rejected.
        $conflictingSlot = $this->createAppointmentSlot(['starts_at' => now()->addDays(20)->setTime(10, 0), 'ends_at' => now()->addDays(20)->setTime(12, 0)]);
        $this->reschedule($admin['access_token'], UuidBinary::toString($fixtureB['booking']->id), $conflictingSlot['uuid'])->assertStatus(409);
        $this->assertSame(0, $this->auditCount());

        // Does not overlap either Booking - must succeed.
        $freeSlot = $this->createAppointmentSlot(['starts_at' => now()->addDays(22)->setTime(9, 0), 'ends_at' => now()->addDays(22)->setTime(11, 0)]);
        $this->reschedule($admin['access_token'], UuidBinary::toString($fixtureB['booking']->id), $freeSlot['uuid'])->assertStatus(200);
        $this->assertSame(1, $this->auditCount());
    }
}
