<?php

namespace Tests\Feature\Auth;

use App\Support\Auth\PendingAccountDeletionGuard;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * App\Support\Auth\PendingAccountDeletionGuard, exercised through the real
 * HTTP entry points that create a NEW Booking/Payment/Contract obligation
 * (App\Actions\Payment\CreatePaymentAttemptAction, App\Actions\Contract\
 * RequestContractAction, App\Actions\Contract\CreateContractBookingAction)
 * - proves a customer with a PENDING deletion request cannot postpone
 * their own deletion forever by continuously creating new obligations,
 * while everything needed to resolve an EXISTING obligation (reading
 * Bookings/Contracts/profile, cancelling a Booking, cart preparation with
 * no Payment yet) remains fully usable.
 */
class PendingAccountDeletionGuardTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    private const FIXTURE_PASSWORD = 'CartTestPassw0rd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCartFixtures();
    }

    private function deleteAccount(string $accessToken)
    {
        return $this->deleteJson('/api/v1/auth/account', [
            'current_password' => self::FIXTURE_PASSWORD,
        ], ['Authorization' => 'Bearer '.$accessToken]);
    }

    /**
     * A customer with a non-terminal Booking (from successfulPayment())
     * whose own deletion request is already PENDING - the shared starting
     * point for every "cannot create a NEW obligation" test below.
     */
    private function customerWithPendingDeletion(): array
    {
        ['customer' => $customer] = $this->successfulPayment();
        $this->deleteAccount($customer['access_token'])->assertStatus(202);

        return $customer;
    }

    private function prepareNewCheckoutFor(string $accessToken): void
    {
        $service = $this->createPricedCartService();
        $this->addCartItem($accessToken, ['service_uuid' => $service['uuid']])->assertStatus(201);

        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($accessToken, $this->locationPayload($areaId))->assertStatus(200);

        $slot = $this->createAppointmentSlot();
        $this->createAppointmentHold($accessToken, $slot['uuid'])->assertStatus(201);
    }

    // ================================================================
    // BLOCKS new-obligation creation while PENDING
    // ================================================================

    public function test_pending_deletion_blocks_new_payment_creation(): void
    {
        $customer = $this->customerWithPendingDeletion();
        $this->prepareNewCheckoutFor($customer['access_token']);

        $countBefore = DB::table('payment_attempts')->count();

        $this->createPayment($customer['access_token'], (string) Str::uuid())
            ->assertStatus(409)
            ->assertJson(['success' => false, 'message' => PendingAccountDeletionGuard::REJECTION_MESSAGE]);

        $this->assertSame($countBefore, DB::table('payment_attempts')->count());
    }

    public function test_pending_deletion_blocks_new_contract_request_creation(): void
    {
        $customer = $this->customerWithPendingDeletion();
        $property = $this->createProperty($customer['access_token']);
        $service = $this->createSubscriptionEligibleService();

        $countBefore = DB::table('service_contracts')->count();

        $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(409)->assertJson(['message' => PendingAccountDeletionGuard::REJECTION_MESSAGE]);

        $this->assertSame($countBefore, DB::table('service_contracts')->count());
    }

    public function test_pending_deletion_blocks_new_contract_booking_creation(): void
    {
        $fixture = $this->activeContractWithItem();
        $customer = $fixture['customer'];

        // The Contract being ACTIVE already makes this customer's own
        // deletion request PENDING - this test proves they additionally
        // cannot keep consuming NEW visits under that same Contract while
        // the request is outstanding, independent of whatever eventually
        // resolves it.
        $this->deleteAccount($customer['access_token'])->assertStatus(202);

        $slot = $this->createAppointmentSlot();
        $countBefore = DB::table('bookings')->count();

        $this->bookContractService(
            $customer['access_token'],
            UuidBinary::toString($fixture['contract']->id),
            UuidBinary::toString($fixture['item']->id),
            $slot['uuid'],
        )->assertStatus(409)->assertJson(['message' => PendingAccountDeletionGuard::REJECTION_MESSAGE]);

        $this->assertSame($countBefore, DB::table('bookings')->count());
    }

    // ================================================================
    // Does NOT block resolving an EXISTING obligation
    // ================================================================

    public function test_pending_deletion_still_allows_reading_bookings(): void
    {
        $customer = $this->customerWithPendingDeletion();

        $this->getJson('/api/v1/bookings', ['Authorization' => 'Bearer '.$customer['access_token']])
            ->assertStatus(200);
    }

    public function test_pending_deletion_still_allows_reading_contracts(): void
    {
        $customer = $this->customerWithPendingDeletion();

        $this->getJson('/api/v1/contracts', ['Authorization' => 'Bearer '.$customer['access_token']])
            ->assertStatus(200);
    }

    public function test_pending_deletion_still_allows_reading_profile(): void
    {
        $customer = $this->customerWithPendingDeletion();

        $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$customer['access_token']])
            ->assertStatus(200);
    }

    public function test_pending_deletion_still_allows_cancelling_the_existing_booking(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $this->deleteAccount($customer['access_token'])->assertStatus(202);

        $this->postJson('/api/v1/bookings/'.UuidBinary::toString($booking->id).'/cancel', [], [
            'Authorization' => 'Bearer '.$customer['access_token'],
        ])->assertStatus(200);
    }

    public function test_pending_deletion_still_allows_cart_preparation_with_no_payment_yet(): void
    {
        $customer = $this->customerWithPendingDeletion();
        $service = $this->createPricedCartService();

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])
            ->assertStatus(201);
    }
}
