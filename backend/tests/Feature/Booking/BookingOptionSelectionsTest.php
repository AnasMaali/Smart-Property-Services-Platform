<?php

namespace Tests\Feature\Booking;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Booking\Concerns\CreatesBookingFixtures;
use Tests\TestCase;

/**
 * Tests that option selections from the cart are correctly snapshotted into
 * booking_item_option_selections / booking_item_option_choice_selections
 * when a booking is created from a successful payment, and that the booking
 * detail API returns those selections.
 */
class BookingOptionSelectionsTest extends TestCase
{
    use CreatesBookingFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_booking_without_options_has_empty_options_array(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();

        $booking = $this->bookingRowForPayment($payment);
        $this->assertNotNull($booking);

        $bookingUuid = UuidBinary::toString($booking->id);
        $response = $this->getBooking($customer['access_token'], $bookingUuid);

        $response->assertStatus(200);
        $items = $response->json('data.booking.items');
        $this->assertCount(1, $items);
        $this->assertSame([], $items[0]['options']);
    }

    public function test_boolean_option_is_snapshotted_into_booking(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartServiceWithBooleanOption();

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'quantity' => 1,
            'options' => [['option_uuid' => $service['option_uuid'], 'boolean_value' => true]],
        ])->assertStatus(201);

        $this->driveToBooking($customer);

        $bookingUuid = $this->latestBookingUuid($customer['user_uuid']);
        $response = $this->getBooking($customer['access_token'], $bookingUuid);

        $response->assertStatus(200);
        $options = $response->json('data.booking.items.0.options');
        $this->assertNotEmpty($options);

        $booleanOption = collect($options)->firstWhere('type', 'BOOLEAN');
        $this->assertNotNull($booleanOption);
        $this->assertTrue($booleanOption['boolean_value']);

        $this->assertDatabaseHas('booking_item_option_selections', [
            'service_option_id' => UuidBinary::toBinary($service['option_uuid']),
            'boolean_value' => 1,
            'option_type_code_snapshot' => 'BOOLEAN',
        ]);
    }

    public function test_numeric_option_is_snapshotted_into_booking(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartServiceWithNumericOption();

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'quantity' => 1,
            'options' => [['option_uuid' => $service['option_uuid'], 'numeric_value' => 3]],
        ])->assertStatus(201);

        $this->driveToBooking($customer);

        $bookingUuid = $this->latestBookingUuid($customer['user_uuid']);
        $response = $this->getBooking($customer['access_token'], $bookingUuid);

        $response->assertStatus(200);
        $options = $response->json('data.booking.items.0.options');

        $numericOption = collect($options)->firstWhere('type', 'NUMBER');
        $this->assertNotNull($numericOption);
        $this->assertSame('3.000000', $numericOption['numeric_value']);
        $this->assertNotNull($numericOption['measurement_unit']);

        $this->assertDatabaseHas('booking_item_option_selections', [
            'service_option_id' => UuidBinary::toBinary($service['option_uuid']),
            'numeric_value' => '3.000000',
            'option_type_code_snapshot' => 'NUMBER',
        ]);
    }

    public function test_single_select_option_is_snapshotted_into_booking(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartServiceWithSingleSelectOption();

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'quantity' => 1,
            'options' => [['option_uuid' => $service['option_uuid'], 'choice_uuids' => [$service['choice_uuid']]]],
        ])->assertStatus(201);

        $this->driveToBooking($customer);

        $bookingUuid = $this->latestBookingUuid($customer['user_uuid']);
        $response = $this->getBooking($customer['access_token'], $bookingUuid);

        $response->assertStatus(200);
        $options = $response->json('data.booking.items.0.options');

        $selectOption = collect($options)->firstWhere('type', 'SINGLE_SELECT');
        $this->assertNotNull($selectOption);
        $this->assertSame($service['option_uuid'], $selectOption['option_uuid']);
        $this->assertCount(1, $selectOption['choices']);
        $this->assertSame($service['choice_uuid'], $selectOption['choices'][0]['uuid']);

        $this->assertDatabaseHas('booking_item_option_choice_selections', [
            'service_option_choice_id' => UuidBinary::toBinary($service['choice_uuid']),
            'option_type_code_snapshot' => 'SINGLE_SELECT',
        ]);
    }

    public function test_multi_select_option_is_snapshotted_into_booking(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartServiceWithMultiSelectOption();

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'quantity' => 1,
            'options' => [['option_uuid' => $service['option_uuid'], 'choice_uuids' => $service['choice_uuids']]],
        ])->assertStatus(201);

        $this->driveToBooking($customer);

        $bookingUuid = $this->latestBookingUuid($customer['user_uuid']);
        $response = $this->getBooking($customer['access_token'], $bookingUuid);

        $response->assertStatus(200);
        $options = $response->json('data.booking.items.0.options');

        $multiOption = collect($options)->firstWhere('type', 'MULTI_SELECT');
        $this->assertNotNull($multiOption);
        $this->assertCount(2, $multiOption['choices']);
    }

    public function test_option_snapshot_is_immutable_after_catalog_change(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartServiceWithBooleanOption();

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'quantity' => 1,
            'options' => [['option_uuid' => $service['option_uuid'], 'boolean_value' => true]],
        ])->assertStatus(201);

        $this->driveToBooking($customer);

        // Change the option name in the catalog after booking creation.
        DB::table('service_options')
            ->where('id', UuidBinary::toBinary($service['option_uuid']))
            ->update(['name' => 'CHANGED OPTION NAME']);

        $bookingUuid = $this->latestBookingUuid($customer['user_uuid']);
        $response = $this->getBooking($customer['access_token'], $bookingUuid);

        $response->assertStatus(200);
        $options = $response->json('data.booking.items.0.options');
        $booleanOption = collect($options)->firstWhere('type', 'BOOLEAN');

        // The snapshot name should be the ORIGINAL name, not "CHANGED OPTION NAME".
        $this->assertNotSame('CHANGED OPTION NAME', $booleanOption['name']);
    }

    // ── Fixture helpers ──

    private function createPricedCartServiceWithBooleanOption(): array
    {
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme);
        $optionUuid = $this->createCartOption($service['uuid'], $this->booleanTypeId, ['code' => 'ADDON_BOOL']);

        return array_merge($service, ['option_uuid' => $optionUuid]);
    }

    private function createPricedCartServiceWithNumericOption(): array
    {
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme);
        $optionUuid = $this->createCartOption($service['uuid'], $this->numberTypeId, ['code' => 'ROOMS']);
        $this->createCartNumericRule($optionUuid);

        return array_merge($service, ['option_uuid' => $optionUuid]);
    }

    private function createPricedCartServiceWithSingleSelectOption(): array
    {
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme);
        $optionUuid = $this->createCartOption($service['uuid'], $this->singleSelectTypeId, ['code' => 'PACKAGE']);
        $this->createCartSelectionRule($optionUuid, ['minimum_selections' => 1, 'maximum_selections' => 1]);
        $choiceUuid = $this->createCartChoice($optionUuid);

        return array_merge($service, ['option_uuid' => $optionUuid, 'choice_uuid' => $choiceUuid]);
    }

    private function createPricedCartServiceWithMultiSelectOption(): array
    {
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme);
        $optionUuid = $this->createCartOption($service['uuid'], $this->multiSelectTypeId, ['code' => 'ADDONS']);
        $this->createCartSelectionRule($optionUuid, ['minimum_selections' => 1, 'maximum_selections' => 3]);
        $choiceA = $this->createCartChoice($optionUuid);
        $choiceB = $this->createCartChoice($optionUuid);

        return array_merge($service, ['option_uuid' => $optionUuid, 'choice_uuids' => [$choiceA, $choiceB]]);
    }

    /**
     * Drives a customer through checkout location + appointment hold + payment + webhook
     * to create a booking from the current cart state.
     */
    private function driveToBooking(array $customer): void
    {
        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);

        $slot = $this->createAppointmentSlot();
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        $createResponse = $this->createPayment($customer['access_token'], (string) \Illuminate\Support\Str::uuid());
        $row = $this->paymentRow($createResponse->json('data.payment.uuid'));

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $row->requested_amount,
        ]))->assertStatus(200);
    }

    private function latestBookingUuid(string $userUuid): string
    {
        $booking = DB::table('bookings')
            ->join('carts', 'carts.id', '=', 'bookings.cart_id')
            ->where('carts.customer_user_id', UuidBinary::toBinary($userUuid))
            ->orderByDesc('bookings.created_at')
            ->first(['bookings.id']);

        return UuidBinary::toString($booking->id);
    }
}
