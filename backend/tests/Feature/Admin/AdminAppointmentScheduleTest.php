<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B27 (Appointment Schedule Management) - Admin day view,
 * slot detail (Bookings/active-holds visibility), capacity update safety
 * (the "never below current occupancy" rule), and close/reopen semantics.
 */
class AdminAppointmentScheduleTest extends TestCase
{
    use CreatesAdminFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    private function day(?string $accessToken, string $date): TestResponse
    {
        return $this->getJson('/api/v1/admin/appointment-schedule?date='.$date, $accessToken === null ? [] : $this->bearer($accessToken));
    }

    private function detail(?string $accessToken, string $slotUuid): TestResponse
    {
        return $this->getJson('/api/v1/admin/appointment-schedule/'.$slotUuid, $accessToken === null ? [] : $this->bearer($accessToken));
    }

    private function updateSlot(?string $accessToken, string $slotUuid, array $payload): TestResponse
    {
        return $this->patchJson('/api/v1/admin/appointment-schedule/'.$slotUuid, $payload, $accessToken === null ? [] : $this->bearer($accessToken));
    }

    private function deactivateSlot(?string $accessToken, string $slotUuid): TestResponse
    {
        return $this->postJson('/api/v1/admin/appointment-schedule/'.$slotUuid.'/deactivate', [], $accessToken === null ? [] : $this->bearer($accessToken));
    }

    private function activateSlot(?string $accessToken, string $slotUuid): TestResponse
    {
        return $this->postJson('/api/v1/admin/appointment-schedule/'.$slotUuid.'/activate', [], $accessToken === null ? [] : $this->bearer($accessToken));
    }

    private function denyCapability(string $code): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', $code)->value('id');
        DB::table('admin_role_permissions')->where('role_id', $adminRoleId)->where('permission_id', $permissionId)->delete();
    }

    /**
     * @return array{customer: array{access_token: string}, payment: object}
     */
    private function successfulPaymentOnSlot(string $slotUuid): array
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid'], 'quantity' => 1])->assertStatus(201);

        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);
        $this->createAppointmentHold($customer['access_token'], $slotUuid)->assertStatus(201);

        $createResponse = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($createResponse->json('data.payment.uuid'));

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $row->requested_amount,
        ]))->assertStatus(200);

        return ['customer' => $customer, 'payment' => $this->paymentRow(UuidBinary::toString($row->id))];
    }

    // -----------------------------------------------------------------
    // Authorization
    // -----------------------------------------------------------------

    public function test_unauthenticated_requests_are_denied(): void
    {
        $slot = $this->createAppointmentSlot();
        $this->day(null, '2026-09-05')->assertStatus(401);
        $this->detail(null, $slot['uuid'])->assertStatus(401);
    }

    public function test_customer_token_cannot_reach_admin_schedule_routes(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $this->day($customer['access_token'], '2026-09-05')->assertStatus(401);
    }

    public function test_view_only_admin_cannot_mutate_a_slot(): void
    {
        $this->denyCapability('appointments.manage');
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $day = now()->addDays(5);
        $slot = $this->createAppointmentSlot(['starts_at' => $day->copy()->setTime(9, 0), 'ends_at' => $day->copy()->setTime(11, 0), 'booking_capacity' => 3]);

        $this->day($admin['access_token'], $day->format('Y-m-d'))->assertStatus(200);
        $this->updateSlot($admin['access_token'], $slot['uuid'], ['booking_capacity' => 5, 'internal_note' => null])->assertStatus(403);
        $this->deactivateSlot($admin['access_token'], $slot['uuid'])->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // Day view / status vocabulary
    // -----------------------------------------------------------------

    public function test_day_view_reports_available_full_and_closed_correctly(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $date = now()->addDays(10)->format('Y-m-d');
        $available = $this->createAppointmentSlot(['starts_at' => now()->addDays(10)->setTime(9, 0), 'ends_at' => now()->addDays(10)->setTime(11, 0), 'booking_capacity' => 3]);
        $full = $this->createAppointmentSlot(['starts_at' => now()->addDays(10)->setTime(11, 0), 'ends_at' => now()->addDays(10)->setTime(13, 0), 'booking_capacity' => 1]);
        $closed = $this->createAppointmentSlot(['starts_at' => now()->addDays(10)->setTime(13, 0), 'ends_at' => now()->addDays(10)->setTime(15, 0), 'is_active' => 0]);

        $this->successfulPaymentOnSlot($full['uuid']);

        $response = $this->day($admin['access_token'], $date)->assertStatus(200);
        $byUuid = collect($response->json('data.appointment_slots'))->keyBy('uuid');

        $this->assertSame('AVAILABLE', $byUuid[$available['uuid']]['availability_status']);
        $this->assertSame(3, $byUuid[$available['uuid']]['remaining_capacity']);

        $this->assertSame('FULL', $byUuid[$full['uuid']]['availability_status']);
        $this->assertSame(0, $byUuid[$full['uuid']]['remaining_capacity']);
        $this->assertSame(1, $byUuid[$full['uuid']]['occupied_capacity']);

        $this->assertSame('CLOSED', $byUuid[$closed['uuid']]['availability_status']);
    }

    public function test_malformed_date_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->day($admin['access_token'], '2026-02-30')->assertStatus(422);
        $this->day($admin['access_token'], 'not-a-date')->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Slot detail: Bookings + active holds visibility
    // -----------------------------------------------------------------

    public function test_slot_detail_shows_bookings_and_active_holds_safely(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $slot = $this->createAppointmentSlot(['booking_capacity' => 3]);

        $booked = $this->successfulPaymentOnSlot($slot['uuid']);

        $holdCustomer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($holdCustomer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        $this->createAppointmentHold($holdCustomer['access_token'], $slot['uuid'])->assertStatus(201);

        $response = $this->detail($admin['access_token'], $slot['uuid'])->assertStatus(200);
        $data = $response->json('data.appointment_slot');

        $this->assertSame(2, $data['occupied_capacity']);
        $this->assertSame(1, $data['active_hold_count']);
        $this->assertCount(1, $data['bookings']);
        $this->assertArrayNotHasKey('id', $data['bookings'][0] ?? [], 'Booking rows must expose a UUID, never a raw numeric/binary id field name confusion.');
        $this->assertSame('PAID', $data['bookings'][0]['status'] ?? $data['bookings'][0]['status']);

        // Active hold rows are safe/non-identifying: only held_at/expires_at.
        $this->assertCount(1, $data['active_holds']);
        $this->assertSame(['held_at', 'expires_at'], array_keys($data['active_holds'][0]));
    }

    public function test_unknown_slot_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->detail($admin['access_token'], UuidBinary::generate())->assertStatus(404);
        $this->detail($admin['access_token'], 'not-a-uuid')->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Capacity update safety - the critical floor rule
    // -----------------------------------------------------------------

    public function test_capacity_can_never_drop_below_current_occupied_count(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $slot = $this->createAppointmentSlot(['booking_capacity' => 3]);

        $this->successfulPaymentOnSlot($slot['uuid']);
        $this->successfulPaymentOnSlot($slot['uuid']);
        // occupied = 2

        // 3 -> 4 (increase): always safe.
        $this->updateSlot($admin['access_token'], $slot['uuid'], ['booking_capacity' => 4, 'internal_note' => null])
            ->assertStatus(200)
            ->assertJsonPath('data.appointment_slot.booking_capacity', 4);

        // 4 -> 2 (equal to occupied): allowed, slot becomes FULL.
        $this->updateSlot($admin['access_token'], $slot['uuid'], ['booking_capacity' => 2, 'internal_note' => null])
            ->assertStatus(200)
            ->assertJsonPath('data.appointment_slot.availability_status', 'FULL');

        // 2 -> 1 (below occupied=2): rejected.
        $this->updateSlot($admin['access_token'], $slot['uuid'], ['booking_capacity' => 1, 'internal_note' => null])
            ->assertStatus(422);

        // Capacity is unchanged after the rejected attempt.
        $slotRow = DB::table('appointment_slots')->where('id', UuidBinary::toBinary($slot['uuid']))->first();
        $this->assertSame(2, (int) $slotRow->booking_capacity);
    }

    public function test_capacity_update_never_alters_existing_booking_records(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $slot = $this->createAppointmentSlot(['booking_capacity' => 3]);
        $fixture = $this->successfulPaymentOnSlot($slot['uuid']);
        $bookingBefore = $this->bookingRowForPayment($fixture['payment']);

        $this->updateSlot($admin['access_token'], $slot['uuid'], ['booking_capacity' => 5, 'internal_note' => 'ops note'])->assertStatus(200);

        $bookingAfter = DB::table('bookings')->where('id', $bookingBefore->id)->first();
        $this->assertEquals($bookingBefore, $bookingAfter);
    }

    // -----------------------------------------------------------------
    // Close / reopen
    // -----------------------------------------------------------------

    public function test_closing_a_slot_with_bookings_warns_but_never_cancels_them(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $slot = $this->createAppointmentSlot(['booking_capacity' => 3]);
        $fixture = $this->successfulPaymentOnSlot($slot['uuid']);
        $booking = $this->bookingRowForPayment($fixture['payment']);

        $response = $this->deactivateSlot($admin['access_token'], $slot['uuid'])->assertStatus(200);

        $this->assertNotNull($response->json('data.warning'));
        $this->assertStringContainsString('does not cancel', $response->json('data.warning'));

        $bookingAfter = DB::table('bookings')->where('id', $booking->id)->first();
        $this->assertSame($booking->status_id, $bookingAfter->status_id, 'Closing a slot must never cancel its existing Bookings.');
    }

    public function test_closed_slot_rejects_new_holds_but_reopen_restores_availability(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $slot = $this->createAppointmentSlot(['booking_capacity' => 3]);

        $this->deactivateSlot($admin['access_token'], $slot['uuid'])->assertStatus(200);

        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        // CreateAppointmentHoldAction's slot lookup itself requires
        // is_active = 1 - an inactive slot is reported as simply not
        // found (404), not as "fully booked" (422).
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(404);

        $this->activateSlot($admin['access_token'], $slot['uuid'])->assertStatus(200);
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);
    }
}
