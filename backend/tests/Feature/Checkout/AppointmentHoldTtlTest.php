<?php

namespace Tests\Feature\Checkout;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Checkout\Concerns\CreatesCheckoutFixtures;
use Tests\TestCase;

/**
 * Appointment hold TTL is an approved BLUE V1 Phase 5 decision (10 minutes,
 * configurable through CHECKOUT_APPOINTMENT_HOLD_TTL_MINUTES) - see
 * config/checkout.php and docs/api-contracts/checkout-v1.md.
 */
class AppointmentHoldTtlTest extends TestCase
{
    use CreatesCheckoutFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_default_configured_ttl_is_ten_minutes(): void
    {
        $this->assertSame(10, config('checkout.appointment_hold_ttl_minutes'));
    }

    public function test_hold_expiry_is_computed_from_the_configured_ttl(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        $slot = $this->createAppointmentSlot();

        $before = now();
        $response = $this->createAppointmentHold($customer['access_token'], $slot['uuid']);
        $after = now();

        $response->assertStatus(201);

        $ttlMinutes = config('checkout.appointment_hold_ttl_minutes');
        $holdUuid = $response->json('data.checkout.appointment.hold_uuid');
        $expiresAt = Carbon::parse(
            DB::table('appointment_holds')->where('id', UuidBinary::toBinary($holdUuid))->value('expires_at')
        );

        $this->assertTrue($expiresAt->greaterThanOrEqualTo($before->copy()->addMinutes($ttlMinutes)));
        $this->assertTrue($expiresAt->lessThanOrEqualTo($after->copy()->addMinutes($ttlMinutes)->addSecond()));
    }

    public function test_expired_hold_is_not_considered_active(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        $slot = $this->createAppointmentSlot();

        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);
        DB::table('appointment_holds')->update(['held_at' => now()->subHour(), 'expires_at' => now()->subMinute()]);

        $response = $this->getCheckout($customer['access_token']);

        $this->assertNull($response->json('data.checkout.appointment'));
        $this->assertFalse($response->json('data.checkout.ready_for_payment'));
    }
}
