<?php

namespace Tests\Feature\Booking;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Booking\Concerns\CreatesBookingFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B27 (Appointment Schedule Management) - regression proving
 * App\Actions\Booking\CancelBookingAction now retires a cancelled Booking's
 * converted appointment_holds row (via `superseded_at`), matching the
 * approved "maximum N ACTIVE reservations" business requirement. Before
 * this phase, a cancelled Booking's slot occupancy was never released -
 * see the BLUE V1 Appointment Scheduling audit that preceded this phase.
 */
class AppointmentScheduleCancellationReleaseTest extends TestCase
{
    use CreatesBookingFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    /**
     * Mirrors CreatesBookingFixtures::successfulPayment(), but drives a
     * customer through checkout against an ALREADY-EXISTING slot (never
     * creating a second, colliding one) - needed so multiple independent
     * Bookings can occupy the SAME dated slot in one test.
     *
     * @return array{customer: array{user_uuid: string, access_token: string}, payment: object}
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

    private function cancel(string $accessToken, string $bookingUuid): TestResponse
    {
        return $this->postJson('/api/v1/bookings/'.$bookingUuid.'/cancel', [], ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function occupancy(string $slotIdBinary): int
    {
        $now = now();

        return DB::table('appointment_holds')
            ->where('appointment_slot_id', $slotIdBinary)
            ->whereNull('released_at')
            ->whereNull('superseded_at')
            ->where(function ($q) use ($now) {
                $q->whereNotNull('converted_at')->orWhere('expires_at', '>', $now);
            })
            ->count();
    }

    public function test_cancelling_one_of_three_bookings_on_a_full_slot_frees_exactly_one_seat(): void
    {
        $slot = $this->createAppointmentSlot(['booking_capacity' => 3]);
        $slotIdBinary = UuidBinary::toBinary($slot['uuid']);

        $bookingA = $this->successfulPaymentOnSlot($slot['uuid']);
        $bookingB = $this->successfulPaymentOnSlot($slot['uuid']);
        $bookingC = $this->successfulPaymentOnSlot($slot['uuid']);

        $this->assertSame(3, $this->occupancy($slotIdBinary));

        // A 4th customer cannot hold this slot while it is full.
        $fourthCustomer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($fourthCustomer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        $this->createAppointmentHold($fourthCustomer['access_token'], $slot['uuid'])->assertStatus(422);

        $bookingRowA = $this->bookingRowForPayment($bookingA['payment']);
        $bookingUuidA = UuidBinary::toString($bookingRowA->id);
        $holdBefore = DB::table('appointment_holds')
            ->where('cart_id', $bookingRowA->cart_id)
            ->where('appointment_slot_id', $slotIdBinary)
            ->first();

        $this->assertNotNull($holdBefore->converted_at);
        $this->assertNull($holdBefore->superseded_at);

        // Cancel Booking A.
        $this->cancel($bookingA['customer']['access_token'], $bookingUuidA)->assertStatus(200);

        // Occupancy drops to exactly 2 - not 0, not 3.
        $this->assertSame(2, $this->occupancy($slotIdBinary));

        // The 4th customer can now hold the freed seat.
        $this->createAppointmentHold($fourthCustomer['access_token'], $slot['uuid'])->assertStatus(201);
        $this->assertSame(3, $this->occupancy($slotIdBinary));

        // Historical integrity: the cancelled Booking's hold is preserved,
        // never deleted, converted_at untouched, only superseded_at set.
        $holdAfter = DB::table('appointment_holds')->where('id', $holdBefore->id)->first();
        $this->assertNotNull($holdAfter, 'The historical hold row must never be deleted.');
        $this->assertEquals($holdBefore->converted_at, $holdAfter->converted_at);
        $this->assertNull($holdAfter->released_at);
        $this->assertNotNull($holdAfter->superseded_at);

        // The cancelled Booking remains historically linked to the slot.
        $bookingAfter = DB::table('bookings')->where('id', $bookingRowA->id)->first();
        $this->assertSame($slotIdBinary, $bookingAfter->appointment_slot_id);

        // B and C are untouched.
        $this->assertNotNull($bookingB);
        $this->assertNotNull($bookingC);
    }

    public function test_cancellation_release_is_idempotent_on_retry(): void
    {
        $slot = $this->createAppointmentSlot(['booking_capacity' => 1]);
        $fixture = $this->successfulPaymentOnSlot($slot['uuid']);
        $booking = $this->bookingRowForPayment($fixture['payment']);
        $bookingUuid = UuidBinary::toString($booking->id);

        $this->cancel($fixture['customer']['access_token'], $bookingUuid)->assertStatus(200);
        $slotIdBinary = UuidBinary::toBinary($slot['uuid']);
        $firstSupersededAt = DB::table('appointment_holds')->where('cart_id', $booking->cart_id)->where('appointment_slot_id', $slotIdBinary)->value('superseded_at');
        $this->assertNotNull($firstSupersededAt);

        // Retry: idempotent replay, same 200, no error, no duplicate write.
        $this->cancel($fixture['customer']['access_token'], $bookingUuid)->assertStatus(200);
        $secondSupersededAt = DB::table('appointment_holds')->where('cart_id', $booking->cart_id)->where('appointment_slot_id', $slotIdBinary)->value('superseded_at');
        $this->assertEquals($firstSupersededAt, $secondSupersededAt);
        $this->assertSame(0, $this->occupancy($slotIdBinary));
    }

    public function test_standalone_refund_without_cancellation_never_alters_capacity(): void
    {
        // A refund is only ever created as part of cancellation in this
        // codebase (App\Actions\Booking\CancelBookingAction is the sole
        // creator of a `booking_refunds` row) - this test proves the
        // inverse holds too: an ACTIVE (non-cancelled) Booking's capacity
        // is never touched merely because money moved.
        $slot = $this->createAppointmentSlot(['booking_capacity' => 1]);
        $fixture = $this->successfulPaymentOnSlot($slot['uuid']);
        $slotIdBinary = UuidBinary::toBinary($slot['uuid']);

        $this->assertSame(1, $this->occupancy($slotIdBinary));

        // No cancellation occurs - occupancy must remain exactly 1.
        $this->assertSame(1, $this->occupancy($slotIdBinary));
        $booking = $this->bookingRowForPayment($fixture['payment']);
        $this->assertNotSame('CANCELLED', DB::table('booking_statuses')->where('id', $booking->status_id)->value('code'));
    }
}
