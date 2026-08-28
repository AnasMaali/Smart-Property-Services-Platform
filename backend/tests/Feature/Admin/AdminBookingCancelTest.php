<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B16 - Admin "Cancel Booking" (App\Actions\Admin\Booking\
 * AdminCancelBookingAction), the ONLY Admin-initiated Booking status
 * transition this phase supports. Reuses App\Actions\Booking\
 * CancelBookingAction end-to-end - this suite proves the Admin wrapper
 * (authorization, mandatory reason, audit) without re-testing the shared
 * cascade/refund logic already covered by tests/Feature/Booking/CancelBookingTest.php.
 */
class AdminBookingCancelTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
        config([
            'cancellation.timezone' => 'UTC',
            'cancellation.before_appointment_day_percentage' => 100,
            'cancellation.appointment_day_percentage' => 75,
        ]);
    }

    private function cancelViaAdmin(string $accessToken, string $bookingUuid, ?string $reason = 'Customer requested cancellation.'): TestResponse
    {
        return $this->postJson(
            '/api/v1/admin/bookings/'.$bookingUuid.'/cancel',
            $reason === null ? [] : ['reason' => $reason],
            $this->bearer($accessToken)
        );
    }

    private function denyBookingsCancelCapability(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'bookings.cancel')->value('id');

        DB::table('admin_role_permissions')->where('role_id', $adminRoleId)->where('permission_id', $permissionId)->delete();
    }

    private function auditCount(): int
    {
        return DB::table('admin_audit_logs')->where('action_code', 'BOOKING_CANCELLED')->count();
    }

    // -----------------------------------------------------------------
    // Authentication / authorization
    // -----------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/v1/admin/bookings/'.UuidBinary::generate().'/cancel', ['reason' => 'Test'])
            ->assertStatus(401);
    }

    public function test_admin_without_bookings_cancel_capability_is_rejected(): void
    {
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);

        $this->denyBookingsCancelCapability();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->cancelViaAdmin($admin['access_token'], UuidBinary::toString($booking->id))->assertStatus(403);
        $this->assertSame(0, $this->auditCount());
    }

    public function test_super_admin_is_allowed_via_the_existing_authorization_override(): void
    {
        $this->denyBookingsCancelCapability();
        $admin = $this->createAndLoginAdmin(['SUPER_ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);

        $this->cancelViaAdmin($admin['access_token'], UuidBinary::toString($booking->id))->assertStatus(200);
    }

    // -----------------------------------------------------------------
    // Not found / validation
    // -----------------------------------------------------------------

    public function test_malformed_and_unknown_booking_uuid_return_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->cancelViaAdmin($admin['access_token'], 'not-a-uuid')->assertStatus(404);
        $this->cancelViaAdmin($admin['access_token'], UuidBinary::generate())->assertStatus(404);
        $this->assertSame(0, $this->auditCount());
    }

    public function test_reason_is_mandatory(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);

        $this->cancelViaAdmin($admin['access_token'], UuidBinary::toString($booking->id), null)->assertStatus(422);
        $this->cancelViaAdmin($admin['access_token'], UuidBinary::toString($booking->id), '  ')->assertStatus(422);

        $this->assertSame(0, $this->auditCount());
        $this->assertSame('PAID', DB::table('booking_statuses')->where('id', DB::table('bookings')->where('id', $booking->id)->value('status_id'))->value('code'));
    }

    // -----------------------------------------------------------------
    // Real cancellation - PAID and ASSIGNED, cascade + reason + audit
    // -----------------------------------------------------------------

    public function test_admin_can_cancel_a_paid_booking_with_reason_recorded_and_refund_computed(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $bookingUuid = UuidBinary::toString($this->bookingRowForPayment($fixture['payment'])->id);

        $response = $this->cancelViaAdmin($admin['access_token'], $bookingUuid, 'Customer called to cancel.')
            ->assertStatus(200);

        $this->assertSame('CANCELLED', $response->json('data.booking.status'));
        $this->assertNotNull($response->json('data.refund_due'));

        $booking = DB::table('bookings')->where('id', UuidBinary::toBinary($bookingUuid))->first();
        $history = DB::table('booking_status_history')->where('booking_id', $booking->id)->orderByDesc('changed_at')->first();

        $this->assertSame('Customer called to cancel.', $history->reason);
        $this->assertSame($admin['user_uuid'], UuidBinary::toString($history->changed_by_user_id));

        $this->assertSame(1, $this->auditCount());
        $audit = DB::table('admin_audit_logs')->where('action_code', 'BOOKING_CANCELLED')->first();
        $this->assertSame('BOOKING', $audit->entity_type);
        $this->assertSame($bookingUuid, $audit->entity_identifier);
        $this->assertSame(['reason' => 'Customer called to cancel.'], json_decode($audit->new_values, true));
        $this->assertNull($audit->old_values);
    }

    public function test_admin_can_cancel_an_assigned_booking_and_releases_the_technician_assignment(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->postJson(
            '/api/v1/admin/booking-items/'.UuidBinary::toString($fixture['item']->id).'/assign-technician',
            ['technician_uuid' => $technician['uuid']],
            $this->bearer($admin['access_token'])
        )->assertStatus(201);

        $bookingUuid = UuidBinary::toString($fixture['booking']->id);
        $this->assertSame('ASSIGNED', DB::table('booking_statuses')->where('id', DB::table('bookings')->where('id', $fixture['booking']->id)->value('status_id'))->value('code'));

        $this->cancelViaAdmin($admin['access_token'], $bookingUuid, 'Admin cancelled - technician unavailable.')
            ->assertStatus(200);

        $item = DB::table('booking_items')->where('id', $fixture['item']->id)->first();
        $this->assertSame('CANCELLED', DB::table('booking_item_statuses')->where('id', $item->status_id)->value('code'));

        $assignment = DB::table('technician_assignments')->where('booking_item_id', $fixture['item']->id)->first();
        $this->assertNotNull($assignment->released_at);
        $this->assertSame('Admin cancelled - technician unavailable.', $assignment->release_reason);
        $this->assertSame($admin['user_uuid'], UuidBinary::toString($assignment->released_by_user_id));

        $this->assertSame(1, $this->auditCount());
    }

    // -----------------------------------------------------------------
    // Terminal-state integrity
    // -----------------------------------------------------------------

    public function test_completed_booking_cannot_be_cancelled(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $itemUuid = UuidBinary::toString($fixture['item']->id);

        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/assign-technician", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(201);
        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/start-work", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);
        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/complete-work", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);

        $bookingUuid = UuidBinary::toString($fixture['booking']->id);
        $this->assertSame('COMPLETED', DB::table('booking_statuses')->where('id', DB::table('bookings')->where('id', $fixture['booking']->id)->value('status_id'))->value('code'));

        $this->cancelViaAdmin($admin['access_token'], $bookingUuid)->assertStatus(409);
        $this->assertSame(0, $this->auditCount());
    }

    public function test_already_cancelled_booking_is_idempotent_and_writes_no_duplicate_audit_event(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $bookingUuid = UuidBinary::toString($this->bookingRowForPayment($fixture['payment'])->id);

        $this->cancelViaAdmin($admin['access_token'], $bookingUuid, 'First cancellation.')->assertStatus(200);
        $this->assertSame(1, $this->auditCount());

        $refundBefore = DB::table('bookings')->where('id', UuidBinary::toBinary($bookingUuid))->first('cancellation_refund_amount');

        $this->cancelViaAdmin($admin['access_token'], $bookingUuid, 'Second attempt.')
            ->assertStatus(200)
            ->assertJson(['message' => 'Booking was already cancelled.']);

        // No second audit event, and the refund snapshot is never recomputed.
        $this->assertSame(1, $this->auditCount());
        $refundAfter = DB::table('bookings')->where('id', UuidBinary::toBinary($bookingUuid))->first('cancellation_refund_amount');
        $this->assertSame((string) $refundBefore->cancellation_refund_amount, (string) $refundAfter->cancellation_refund_amount);
    }

    // -----------------------------------------------------------------
    // Financial isolation
    // -----------------------------------------------------------------

    public function test_cancellation_never_mutates_payment_attempt_amounts(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);
        $paymentBefore = DB::table('payment_attempts')->where('id', $booking->payment_attempt_id)->first();

        $this->cancelViaAdmin($admin['access_token'], UuidBinary::toString($booking->id))->assertStatus(200);

        $paymentAfter = DB::table('payment_attempts')->where('id', $booking->payment_attempt_id)->first();
        $this->assertSame((string) $paymentBefore->confirmed_amount, (string) $paymentAfter->confirmed_amount);
        $this->assertSame($paymentBefore->status_id, $paymentAfter->status_id);
    }

    // -----------------------------------------------------------------
    // GET reflects authoritative post-cancellation state
    // -----------------------------------------------------------------

    public function test_admin_get_booking_reflects_cancellation_afterward(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $bookingUuid = UuidBinary::toString($this->bookingRowForPayment($fixture['payment'])->id);

        $this->cancelViaAdmin($admin['access_token'], $bookingUuid, 'Admin cancellation.')->assertStatus(200);

        $response = $this->getJson('/api/v1/admin/bookings/'.$bookingUuid, $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $this->assertSame('CANCELLED', $response->json('data.booking.status'));
        $this->assertNotNull($response->json('data.booking.cancelled_at'));
        $this->assertNotNull($response->json('data.booking.refund_due'));
    }

    // -----------------------------------------------------------------
    // BLUE V1 Phase B20 - same policy/refund automation as the Customer
    // -----------------------------------------------------------------

    public function test_admin_cancellation_creates_the_same_automatic_refund_obligation_as_customer_cancellation(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);

        $response = $this->cancelViaAdmin($admin['access_token'], UuidBinary::toString($booking->id), 'Admin-initiated cancellation.')
            ->assertStatus(200);

        $this->assertSame('AUTOMATIC', $response->json('data.refund_due.execution'));

        $refundRow = DB::table('booking_refunds')->where('booking_id', $booking->id)->first();
        $this->assertNotNull($refundRow);
        $this->assertSame('ADMIN', $refundRow->initiated_as);
        $this->assertSame($admin['user_uuid'], UuidBinary::toString($refundRow->initiated_by_user_id));

        $statusCode = DB::table('booking_refund_statuses')->where('id', $refundRow->status_id)->value('code');
        $this->assertSame('SUCCEEDED', $statusCode);
    }

    public function test_admin_cannot_cancel_a_booking_after_its_appointment_has_started_no_bypass(): void
    {
        // The Admin session is minted at real wall-clock time (its JWT nbf/
        // exp are validated by firebase/php-jwt against the REAL system
        // clock, never Carbon::setTestNow() - see App\Services\Auth\
        // JwtTokenService) - a session minted while Carbon is already
        // frozen to a future instant would look "not yet valid" instead.
        // A tiny (minutes-scale) Carbon jump afterward keeps
        // AdminSessionPolicy's own idle-timeout check (which IS
        // Carbon-based) comfortably inside its window.
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $startsAt = Carbon::now()->addMinutes(2);
        $fixture = $this->successfulPayment(['starts_at' => $startsAt]);
        $booking = $this->bookingRowForPayment($fixture['payment']);

        Carbon::setTestNow($startsAt->copy()->addSecond());

        $this->cancelViaAdmin($admin['access_token'], UuidBinary::toString($booking->id), 'Too late.')
            ->assertStatus(409);

        $this->assertSame('PAID', DB::table('booking_statuses')->where('id', DB::table('bookings')->where('id', $booking->id)->value('status_id'))->value('code'));
        $this->assertSame(0, $this->auditCount());

        Carbon::setTestNow();
    }
}
