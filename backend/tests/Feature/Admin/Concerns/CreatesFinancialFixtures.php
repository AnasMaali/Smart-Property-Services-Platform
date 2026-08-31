<?php

namespace Tests\Feature\Admin\Concerns;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Booking\Concerns\CreatesBookingFixtures;
use Tests\Feature\Technician\Concerns\CreatesTechnicianFixtures;

/**
 * Shared money-movement fixture builders for the Admin Financial Dashboard
 * and Ledger test suites - one successful event per BLUE V1 authoritative
 * payment source (see App\Support\Admin\AdminFinancialSummaryCalculator's
 * class docblock for the full source-of-truth map these mirror exactly).
 * Every fixture drives the real HTTP/webhook flow an actual event would
 * take - never a direct DB insert - so these tests exercise the same code
 * path production money actually moves through.
 */
trait CreatesFinancialFixtures
{
    use CreatesBookingFixtures;
    use CreatesTechnicianFixtures;

    /**
     * A successful Apple Pay checkout payment - mirrors successfulPayment()
     * exactly except for the declared payment_method and the webhook's
     * reported wallet type.
     *
     * @return array{customer: array, payment: object}
     */
    private function successfulApplePayPayment(array $slotOverrides = []): array
    {
        $customer = $this->readyForPaymentCustomer($slotOverrides);
        $createResponse = $this->createPayment($customer['access_token'], (string) Str::uuid(), 'APPLE_PAY');
        $row = $this->paymentRow($createResponse->json('data.payment.uuid'));

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $row->requested_amount,
            'payment_method_type' => 'apple_pay',
        ]))->assertStatus(200);

        return ['customer' => $customer, 'payment' => $this->paymentRow(UuidBinary::toString($row->id))];
    }

    /**
     * @return array{customer: array, service: array}
     */
    private function readyPayOnSiteCustomer(): array
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService(overrides: ['payment_methods' => ['PAY_ON_SITE', 'CARD']]);
        $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($this->schemeForLatestVersion($service['uuid']));

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);
        $slot = $this->createAppointmentSlot();
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        return ['customer' => $customer, 'service' => $service];
    }

    private function schemeForLatestVersion(string $serviceUuid): string
    {
        $versionId = DB::table('pricing_scheme_versions')
            ->where('service_id', UuidBinary::toBinary($serviceUuid))
            ->orderByDesc('created_at')
            ->value('id');

        return UuidBinary::toString($versionId);
    }

    /**
     * A confirmed but not-yet-collected Pay-on-Site Booking.
     *
     * @return array{customer: array, booking: object}
     */
    private function pendingPayOnSiteBooking(): array
    {
        $fixture = $this->readyPayOnSiteCustomer();
        $key = (string) Str::uuid();

        $headers = ['Authorization' => 'Bearer '.$fixture['customer']['access_token'], 'Idempotency-Key' => $key];
        $response = $this->postJson('/api/v1/bookings/pay-on-site', [], $headers);
        $response->assertStatus(201);

        $bookingUuid = $response->json('data.booking.uuid');

        return ['customer' => $fixture['customer'], 'booking' => $this->bookingRow($bookingUuid)];
    }

    /**
     * A Pay-on-Site Booking an Admin has actually collected cash for.
     *
     * @return array{customer: array, booking: object, admin: array}
     */
    private function collectedPayOnSiteBooking(): array
    {
        $pending = $this->pendingPayOnSiteBooking();
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);

        $this->postJson(
            '/api/v1/admin/bookings/'.UuidBinary::toString($pending['booking']->id).'/collect-on-site-payment',
            [],
            $this->bearer($admin['access_token'])
        )->assertStatus(200);

        return [
            'customer' => $pending['customer'],
            'booking' => $this->bookingRow(UuidBinary::toString($pending['booking']->id)),
            'admin' => $admin,
        ];
    }

    /**
     * A Booking cancelled early enough to be 100%-refund-eligible, whose
     * Stripe refund succeeds synchronously - App\Support\Payment\Gateway\
     * FakePaymentGateway::refundPayment()'s own default (no queueNextRefund
     * call needed) is `RefundCreationResult::created(..., providerStatusCode:
     * 'succeeded')`, which App\Actions\Payment\ExecuteBookingRefundAction::
     * persistCreated() finalizes as SUCCEEDED in the same request - no
     * separate refund webhook needed for this fixture.
     *
     * @return array{customer: array, payment: object, booking: object, refund: object}
     */
    private function succeededRefund(): array
    {
        config([
            'cancellation.timezone' => 'UTC',
            'cancellation.before_appointment_day_percentage' => 100,
            'cancellation.appointment_day_percentage' => 75,
        ]);

        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => now()->addDays(2),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        $this->postJson(
            '/api/v1/bookings/'.UuidBinary::toString($booking->id).'/cancel',
            [],
            ['Authorization' => 'Bearer '.$customer['access_token']]
        )->assertStatus(200);

        return [
            'customer' => $customer,
            'payment' => $payment,
            'booking' => $this->bookingRow(UuidBinary::toString($booking->id)),
            'refund' => $this->bookingRefundRow($booking),
        ];
    }

    /**
     * The full Inspection -> Repair Quote -> Historical Credit -> Balance
     * Payment flow, condensed from InspectionQuoteCreditV1Test's own
     * golden-path fixture, ending with a SUCCESSFUL
     * `repair_quote_payment_attempts` row.
     *
     * @return array{customer: array, booking: object, quote_uuid: string, inspection_payment: object, balance_payment: object}
     */
    private function succeededRepairQuoteBalancePayment(string $inspectionPrice = '150.000000', string $quotedAmount = '1000'): array
    {
        $service = $this->createCartService(overrides: []);
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['effect_amount' => $inspectionPrice]);
        DB::table('services')->where('id', UuidBinary::toBinary($service['uuid']))->update(['inspection_quote_credit_enabled' => 1]);

        $specializationId = $this->createSpecialization();
        $this->linkServiceSpecialization($service['uuid'], $specializationId);

        $customer = $this->createAuthenticatedCartCustomer();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid'], 'quantity' => 1])->assertStatus(201);

        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);
        $slot = $this->createAppointmentSlot();
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        $createResponse = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $inspectionPaymentRow = $this->paymentRow($createResponse->json('data.payment.uuid'));

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $inspectionPaymentRow->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $inspectionPaymentRow->requested_amount,
        ]))->assertStatus(200);

        $inspectionPayment = $this->paymentRow(UuidBinary::toString($inspectionPaymentRow->id));
        $booking = $this->bookingRowForPayment($inspectionPayment);
        $item = DB::table('booking_items')->where('booking_id', $booking->id)->first();

        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $technician = $this->createEligibleTechnician($specializationId);
        $itemUuid = UuidBinary::toString($item->id);

        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/assign-technician", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(201);
        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/start-work", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);
        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/complete-work", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);

        $create = $this->postJson(
            "/api/v1/admin/booking-items/{$itemUuid}/repair-quotes",
            ['quoted_amount' => $quotedAmount],
            $this->bearer($admin['access_token'])
        );
        $create->assertStatus(201);
        $quoteUuid = $create->json('data.quote.uuid');

        $this->postJson("/api/v1/admin/repair-quotes/{$quoteUuid}/send", [], $this->bearer($admin['access_token']))->assertStatus(200);

        $bookingUuid = UuidBinary::toString($booking->id);
        $this->postJson('/api/v1/bookings/'.$bookingUuid.'/quote/accept', [], ['Authorization' => 'Bearer '.$customer['access_token']])
            ->assertStatus(200);

        $pay = $this->postJson('/api/v1/bookings/'.$bookingUuid.'/quote/pay-balance', [], [
            'Authorization' => 'Bearer '.$customer['access_token'],
            'Idempotency-Key' => (string) Str::uuid(),
        ]);
        $pay->assertStatus(201);

        $balanceRow = DB::table('repair_quote_payment_attempts')->where('quote_id', UuidBinary::toBinary($quoteUuid))->first();

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $balanceRow->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $balanceRow->requested_amount,
        ]))->assertStatus(200);

        return [
            'customer' => $customer,
            'booking' => $this->bookingRow($bookingUuid),
            'quote_uuid' => $quoteUuid,
            'inspection_payment' => $inspectionPayment,
            'balance_payment' => DB::table('repair_quote_payment_attempts')->where('id', $balanceRow->id)->first(),
        ];
    }
}
