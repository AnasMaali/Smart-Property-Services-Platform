<?php

namespace Tests\Feature\Contract;

use App\Support\Booking\BookingStatuses;
use App\Support\Contract\ContractStatuses;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

class ContractBookingTest extends TestCase
{
    use CreatesContractFixtures;
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

    private function startUrl(object $item): string
    {
        return '/api/v1/admin/booking-items/'.UuidBinary::toString($item->id).'/start-work';
    }

    private function completeUrl(object $item): string
    {
        return '/api/v1/admin/booking-items/'.UuidBinary::toString($item->id).'/complete-work';
    }

    /**
     * Successive calls within the same test run can land in the same
     * wall-clock second, and appointment_slots.uq_appointment_slots_period
     * is a (starts_at, ends_at) unique key - $index spreads each slot far
     * enough apart to never collide, regardless of real elapsed time.
     */
    private function nextAppointmentSlot(int $index, array $overrides = []): array
    {
        return $this->createAppointmentSlot(array_merge([
            'starts_at' => now()->addDay()->addHours($index * 3),
        ], $overrides));
    }

    public function test_customer_can_book_a_contract_covered_service(): void
    {
        $built = $this->activeContractWithItem();
        $slot = $this->createAppointmentSlot();
        $contractUuid = UuidBinary::toString($built['contract']->id);
        $itemUuid = UuidBinary::toString($built['item']->id);

        $response = $this->bookContractService($built['customer']['access_token'], $contractUuid, $itemUuid, $slot['uuid']);

        $response->assertStatus(201)->assertJson(['success' => true]);
        $this->assertSame('PAID', $response->json('data.booking.status'));
        $this->assertSame('CONTRACT', $response->json('data.booking.source'));
        $this->assertSame($contractUuid, $response->json('data.booking.contract.contract_uuid'));
        $this->assertSame('0.000000', $response->json('data.booking.total'));

        $bookingRow = DB::table('bookings')->where('id', UuidBinary::toBinary($response->json('data.booking.uuid')))->first();
        $this->assertNull($bookingRow->payment_attempt_id);
        $this->assertNotNull($bookingRow->service_contract_id);
        $this->assertNotNull($bookingRow->service_contract_item_id);
    }

    public function test_booking_rejected_when_contract_not_yet_active(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $service = $this->createSubscriptionEligibleService();

        $created = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(201);

        $admin = $this->createAndLoginAdmin();
        $this->adminApproveContract($admin['access_token'], $created->json('data.contract.uuid'), $this->approveContractPayload($service['uuid']))->assertStatus(200);
        // Never sent for acceptance / accepted - still APPROVED, not ACTIVE.

        $itemUuid = $this->contractItemRows($created->json('data.contract.uuid'))->first()->id;
        $slot = $this->createAppointmentSlot();

        $response = $this->bookContractService(
            $customer['access_token'],
            $created->json('data.contract.uuid'),
            UuidBinary::toString($itemUuid),
            $slot['uuid']
        );

        $response->assertStatus(409);
    }

    public function test_booking_rejected_before_contract_term_starts(): void
    {
        $built = $this->activeContractWithItem([
            'approve' => ['starts_at' => now()->addDays(5)->toIso8601String(), 'ends_at' => now()->addDays(35)->toIso8601String()],
        ]);
        $slot = $this->createAppointmentSlot();

        $response = $this->bookContractService(
            $built['customer']['access_token'],
            UuidBinary::toString($built['contract']->id),
            UuidBinary::toString($built['item']->id),
            $slot['uuid']
        );

        $response->assertStatus(409);
    }

    public function test_booking_rejected_after_contract_expires(): void
    {
        $built = $this->activeContractWithItem();
        $startsAt = Carbon::parse($built['contract']->starts_at);
        DB::table('service_contracts')->where('id', $built['contract']->id)->update(['ends_at' => $startsAt->copy()->addHours(12)]);
        $slot = $this->createAppointmentSlot();

        $response = $this->bookContractService(
            $built['customer']['access_token'],
            UuidBinary::toString($built['contract']->id),
            UuidBinary::toString($built['item']->id),
            $slot['uuid']
        );

        $response->assertStatus(409);

        $row = DB::table('service_contracts')->where('id', $built['contract']->id)->first();
        $this->assertSame('EXPIRED', ContractStatuses::code((int) $row->status_id));
    }

    public function test_booking_rejects_a_contract_item_from_another_contract(): void
    {
        $built = $this->activeContractWithItem();
        $otherBuilt = $this->activeContractWithItem();
        $slot = $this->createAppointmentSlot();

        $response = $this->bookContractService(
            $built['customer']['access_token'],
            UuidBinary::toString($built['contract']->id),
            UuidBinary::toString($otherBuilt['item']->id),
            $slot['uuid']
        );

        $response->assertStatus(404);
    }

    public function test_foreign_customer_cannot_book_against_another_customers_contract(): void
    {
        $built = $this->activeContractWithItem();
        $stranger = $this->createAuthenticatedCartCustomer();
        $slot = $this->createAppointmentSlot();

        $response = $this->bookContractService(
            $stranger['access_token'],
            UuidBinary::toString($built['contract']->id),
            UuidBinary::toString($built['item']->id),
            $slot['uuid']
        );

        $response->assertStatus(404);
    }

    public function test_appointment_capacity_is_respected_across_contract_bookings(): void
    {
        $slot = $this->createAppointmentSlot(['booking_capacity' => 1]);

        $first = $this->activeContractWithItem();
        $second = $this->activeContractWithItem();

        $this->bookContractService(
            $first['customer']['access_token'],
            UuidBinary::toString($first['contract']->id),
            UuidBinary::toString($first['item']->id),
            $slot['uuid']
        )->assertStatus(201);

        $response = $this->bookContractService(
            $second['customer']['access_token'],
            UuidBinary::toString($second['contract']->id),
            UuidBinary::toString($second['item']->id),
            $slot['uuid']
        );

        $response->assertStatus(422);
    }

    public function test_limited_visits_entitlement_is_enforced(): void
    {
        $built = $this->activeContractWithItem(); // included_visits = 2

        $this->bookContractService($built['customer']['access_token'], UuidBinary::toString($built['contract']->id), UuidBinary::toString($built['item']->id), $this->nextAppointmentSlot(0)['uuid'])->assertStatus(201);
        $this->bookContractService($built['customer']['access_token'], UuidBinary::toString($built['contract']->id), UuidBinary::toString($built['item']->id), $this->nextAppointmentSlot(1)['uuid'])->assertStatus(201);

        $third = $this->bookContractService($built['customer']['access_token'], UuidBinary::toString($built['contract']->id), UuidBinary::toString($built['item']->id), $this->nextAppointmentSlot(2)['uuid']);

        $third->assertStatus(409);
    }

    public function test_unlimited_entitlement_has_no_cap(): void
    {
        $service = $this->createSubscriptionEligibleService();
        $built = $this->activeContractWithItem([
            'service' => $service,
            'approve' => ['services' => [['service_uuid' => $service['uuid'], 'entitlement_mode' => 'UNLIMITED']]],
        ]);

        for ($i = 0; $i < 4; $i++) {
            $this->bookContractService(
                $built['customer']['access_token'],
                UuidBinary::toString($built['contract']->id),
                UuidBinary::toString($built['item']->id),
                $this->nextAppointmentSlot($i)['uuid']
            )->assertStatus(201);
        }

        $detail = $this->getContractHttp($built['customer']['access_token'], UuidBinary::toString($built['contract']->id));
        $this->assertSame(4, $detail->json('data.contract.covered_services.0.used_visits'));
        $this->assertNull($detail->json('data.contract.covered_services.0.remaining_visits'));
    }

    public function test_used_and_remaining_visits_are_reported_correctly(): void
    {
        $built = $this->activeContractWithItem(); // included_visits = 2

        $this->bookContractService($built['customer']['access_token'], UuidBinary::toString($built['contract']->id), UuidBinary::toString($built['item']->id), $this->createAppointmentSlot()['uuid'])->assertStatus(201);

        $detail = $this->getContractHttp($built['customer']['access_token'], UuidBinary::toString($built['contract']->id));

        $this->assertSame(1, $detail->json('data.contract.covered_services.0.used_visits'));
        $this->assertSame(1, $detail->json('data.contract.covered_services.0.remaining_visits'));
        $this->assertCount(1, $detail->json('data.contract.bookings'));
    }

    public function test_cancelled_contract_booking_frees_the_entitlement(): void
    {
        $built = $this->activeContractWithItem(); // included_visits = 2
        $contractUuid = UuidBinary::toString($built['contract']->id);
        $itemUuid = UuidBinary::toString($built['item']->id);

        $bookingA = $this->bookContractService($built['customer']['access_token'], $contractUuid, $itemUuid, $this->nextAppointmentSlot(0)['uuid'])->assertStatus(201);
        $this->bookContractService($built['customer']['access_token'], $contractUuid, $itemUuid, $this->nextAppointmentSlot(1)['uuid'])->assertStatus(201);

        // Entitlement now fully consumed.
        $this->bookContractService($built['customer']['access_token'], $contractUuid, $itemUuid, $this->nextAppointmentSlot(2)['uuid'])->assertStatus(409);

        $this->postJson('/api/v1/bookings/'.$bookingA->json('data.booking.uuid').'/cancel', [], ['Authorization' => 'Bearer '.$built['customer']['access_token']])->assertStatus(200);

        $response = $this->bookContractService($built['customer']['access_token'], $contractUuid, $itemUuid, $this->nextAppointmentSlot(3)['uuid']);

        $response->assertStatus(201);
    }

    public function test_cancelling_a_contract_booking_never_creates_a_refund_snapshot(): void
    {
        $built = $this->activeContractWithItem();
        $booking = $this->bookContractService(
            $built['customer']['access_token'],
            UuidBinary::toString($built['contract']->id),
            UuidBinary::toString($built['item']->id),
            $this->createAppointmentSlot()['uuid']
        )->assertStatus(201);

        $response = $this->postJson('/api/v1/bookings/'.$booking->json('data.booking.uuid').'/cancel', [], ['Authorization' => 'Bearer '.$built['customer']['access_token']]);

        $response->assertStatus(200);
        $this->assertNull($response->json('data.refund_due'));

        // BLUE V1 Phase B20 - a Contract Booking never triggers a Stripe
        // refund obligation: it was never paid for directly.
        $bookingIdBinary = UuidBinary::toBinary($booking->json('data.booking.uuid'));
        $this->assertSame(0, DB::table('booking_refunds')->where('booking_id', $bookingIdBinary)->count());
        $this->assertCount(0, $this->fakeGateway()->refundPaymentCalls);
    }

    public function test_customer_can_still_book_a_normal_service_not_covered_by_the_contract(): void
    {
        $built = $this->activeContractWithItem();
        $standardService = $this->createPricedCartService();

        $this->addCartItem($built['customer']['access_token'], ['service_uuid' => $standardService['uuid'], 'quantity' => 1])->assertStatus(201);

        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($built['customer']['access_token'], $this->locationPayload($areaId))->assertStatus(200);

        $slot = $this->createAppointmentSlot();
        $this->createAppointmentHold($built['customer']['access_token'], $slot['uuid'])->assertStatus(201);

        $createResponse = $this->createPayment($built['customer']['access_token'], (string) Str::uuid());
        $createResponse->assertStatus(201);

        $paymentRow = $this->paymentRow($createResponse->json('data.payment.uuid'));
        $webhook = $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $paymentRow->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $paymentRow->requested_amount,
        ]));

        $webhook->assertStatus(200);

        $booking = $this->bookingRowForPayment($this->paymentRow(UuidBinary::toString($paymentRow->id)));
        $this->assertNotNull($booking);
        $this->assertNotNull($booking->payment_attempt_id);
        $this->assertNull($booking->service_contract_id);
    }

    public function test_standard_payment_booking_flow_is_unaffected_by_contracts(): void
    {
        $payment = $this->successfulPayment();

        $booking = $this->bookingRowForPayment($payment['payment']);

        $this->assertNotNull($booking);
        $this->assertNotNull($booking->payment_attempt_id);
        $this->assertNull($booking->service_contract_id);
        $this->assertNull($booking->service_contract_item_id);

        $response = $this->getBooking($payment['customer']['access_token'], UuidBinary::toString($booking->id));
        $response->assertStatus(200);
        $this->assertSame('STANDARD', $response->json('data.booking.source'));
        $this->assertNull($response->json('data.booking.contract'));
    }

    public function test_technician_assignment_start_and_complete_work_unchanged_for_contract_booking(): void
    {
        $specializationId = $this->createSpecialization();
        $service = $this->createSubscriptionEligibleService();
        $this->linkServiceSpecialization($service['uuid'], $specializationId);

        $built = $this->activeContractWithItem(['service' => $service]);
        $technician = $this->createEligibleTechnician($specializationId);

        $slot = $this->createAppointmentSlot();
        $bookingResponse = $this->bookContractService(
            $built['customer']['access_token'],
            UuidBinary::toString($built['contract']->id),
            UuidBinary::toString($built['item']->id),
            $slot['uuid']
        )->assertStatus(201);

        $bookingUuid = $bookingResponse->json('data.booking.uuid');
        $item = DB::table('booking_items')->where('booking_id', UuidBinary::toBinary($bookingUuid))->first();

        $this->postJson($this->assignUrl($item), ['technician_uuid' => $technician['uuid']], $this->bearer($built['admin']['access_token']))
            ->assertStatus(201);

        $this->postJson($this->startUrl($item), ['technician_uuid' => $technician['uuid']], $this->bearer($built['admin']['access_token']))
            ->assertStatus(200);

        $this->postJson($this->completeUrl($item), ['technician_uuid' => $technician['uuid']], $this->bearer($built['admin']['access_token']))
            ->assertStatus(200);

        $bookingRow = DB::table('bookings')->where('id', UuidBinary::toBinary($bookingUuid))->first();
        $this->assertSame('COMPLETED', BookingStatuses::code((int) $bookingRow->status_id));
    }
}
