<?php

namespace Tests\Feature\Admin\Concerns;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\Feature\Technician\Concerns\CreatesTechnicianFixtures;

/**
 * Shared money-movement fixture builders for the Admin Financial Dashboard
 * and Ledger test suites - one successful event per BLUE V1 authoritative
 * payment source (see App\Support\Admin\AdminFinancialSummaryCalculator's
 * class docblock for the full source-of-truth map these mirror exactly).
 * Card / Apple Pay / Refund fixtures drive the real HTTP/webhook flow.
 * Pay-on-Site collection and Repair Quote balance fixtures persist into
 * the production ledger tables those READ surfaces query: the matching
 * write APIs are not in this backend.
 */
trait CreatesFinancialFixtures
{
    use CreatesContractFixtures;
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
     * A Booking with no card/Apple-Pay `payment_attempts` row (a Contract
     * Booking) plus an uncollected `booking_on_site_settlements` row.
     * Pay-on-Site write APIs are not in this backend; the Admin Financial
     * READ surfaces query the settlement table directly.
     *
     * @return array{customer: array, booking: object}
     */
    private function pendingPayOnSiteBooking(): array
    {
        $fixture = $this->contractBookingForOnSiteSettlement();
        $this->insertOnSiteSettlement($fixture['booking']->id, collected: false);

        return ['customer' => $fixture['customer'], 'booking' => $fixture['booking']];
    }

    /**
     * A Contract Booking whose on-site settlement has been collected.
     *
     * @return array{customer: array, booking: object, admin: array}
     */
    private function collectedPayOnSiteBooking(): array
    {
        $fixture = $this->contractBookingForOnSiteSettlement();
        $this->insertOnSiteSettlement($fixture['booking']->id, collected: true);

        return [
            'customer' => $fixture['customer'],
            'booking' => $fixture['booking'],
            'admin' => $fixture['admin'],
        ];
    }

    /**
     * @return array{customer: array, admin: array, booking: object}
     */
    private function contractBookingForOnSiteSettlement(): array
    {
        $built = $this->activeContractWithItem();
        $slot = $this->createAppointmentSlot();
        $response = $this->bookContractService(
            $built['customer']['access_token'],
            UuidBinary::toString($built['contract']->id),
            UuidBinary::toString($built['item']->id),
            $slot['uuid'],
        );
        $response->assertStatus(201);

        return [
            'customer' => $built['customer'],
            'admin' => $built['admin'],
            'booking' => $this->bookingRow($response->json('data.booking.uuid')),
        ];
    }

    private function insertOnSiteSettlement(string $bookingIdBinary, bool $collected): void
    {
        $now = now()->format('Y-m-d H:i:s.u');

        DB::table('booking_on_site_settlements')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'booking_id' => $bookingIdBinary,
            'amount_due' => '100.000000',
            'amount_collected' => $collected ? '100.000000' : null,
            'collected_at' => $collected ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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
     * An inspection payment (real checkout webhook) plus the matching
     * `booking_item_repair_quotes` / `repair_quote_credits` /
     * `repair_quote_payment_attempts` rows the Admin Financial READ
     * surfaces query. Repair Quote write APIs are not in this backend;
     * columns here match the production tables Phase 29 synced.
     *
     * @return array{customer: array, booking: object, quote_uuid: string, inspection_payment: object, balance_payment: object}
     */
    private function succeededRepairQuoteBalancePayment(string $inspectionPrice = '150.000000', string $quotedAmount = '1000'): array
    {
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['effect_amount' => $inspectionPrice]);

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
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $quoted = bcadd($quotedAmount, '0', 6);
        $credit = bcadd($inspectionPrice, '0', 6);
        $balance = bcsub($quoted, $credit, 6);
        $now = now()->format('Y-m-d H:i:s.u');
        $quoteUuid = UuidBinary::generate();
        $quoteIdBinary = UuidBinary::toBinary($quoteUuid);
        $attemptIdBinary = UuidBinary::toBinary(UuidBinary::generate());
        $acceptedStatusId = (int) DB::table('booking_item_repair_quote_statuses')->where('code', 'ACCEPTED')->value('id');
        $successfulStatusId = (int) DB::table('payment_statuses')->where('code', 'SUCCESSFUL')->value('id');
        $aedCurrencyId = (int) DB::table('currencies')->where('code', 'AED')->value('id');

        DB::table('booking_item_repair_quotes')->insert([
            'id' => $quoteIdBinary,
            'booking_id' => $booking->id,
            'booking_item_id' => $item->id,
            'status_id' => $acceptedStatusId,
            'currency_id' => $aedCurrencyId,
            'quoted_amount' => $quoted,
            'credit_amount' => $credit,
            'balance_due_amount' => $balance,
            'created_by_admin_user_id' => UuidBinary::toBinary($admin['user_uuid']),
            'sent_at' => $now,
            'accepted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('repair_quote_credits')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'quote_id' => $quoteIdBinary,
            'source_booking_id' => $booking->id,
            'source_booking_item_id' => $item->id,
            'source_payment_attempt_id' => $inspectionPayment->id,
            'amount' => $credit,
            'created_at' => $now,
        ]);

        DB::table('repair_quote_payment_attempts')->insert([
            'id' => $attemptIdBinary,
            'quote_id' => $quoteIdBinary,
            'status_id' => $successfulStatusId,
            'currency_id' => $aedCurrencyId,
            'reference' => 'RQPA'.substr(str_replace('-', '', $quoteUuid), 0, 12),
            'idempotency_key' => random_bytes(32),
            'provider_code' => 'FAKE',
            'provider_session_reference' => 'rq_sess_'.str_replace('-', '', $quoteUuid),
            'requested_amount' => $balance,
            'confirmed_amount' => $balance,
            'payment_method_code' => 'CARD',
            'successful_at' => $now,
            'finalized_at' => $now,
            'status_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'customer' => $customer,
            'booking' => $this->bookingRow(UuidBinary::toString($booking->id)),
            'quote_uuid' => $quoteUuid,
            'inspection_payment' => $inspectionPayment,
            'balance_payment' => DB::table('repair_quote_payment_attempts')->where('id', $attemptIdBinary)->first(),
        ];
    }
}
