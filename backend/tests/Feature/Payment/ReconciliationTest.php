<?php

namespace Tests\Feature\Payment;

use App\Support\Payment\CheckoutReferenceGenerator;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Payment\Concerns\CreatesPaymentFixtures;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use CreatesPaymentFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    private function createPendingPayment(): array
    {
        $customer = $this->readyForPaymentCustomer();
        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($response->json('data.payment.uuid'));

        return ['customer' => $customer, 'row' => $row];
    }

    public function test_amount_mismatch_still_marks_successful_but_flags_reconciliation(): void
    {
        ['row' => $row] = $this->createPendingPayment();

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => '1.000000',
        ]))->assertStatus(200);

        $fresh = $this->paymentRow(UuidBinary::toString($row->id));
        $this->assertSame('SUCCESSFUL', $this->paymentStatusCode($fresh));
        $this->assertSame(1, (int) $fresh->requires_reconciliation);
        $this->assertSame('AMOUNT_MISMATCH', $fresh->reconciliation_reason_code);
        $this->assertSame(0, DB::table('bookings')->count());
    }

    public function test_currency_mismatch_still_marks_successful_but_flags_reconciliation(): void
    {
        ['row' => $row] = $this->createPendingPayment();

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $row->requested_amount,
            'currency' => 'USD',
        ]))->assertStatus(200);

        $fresh = $this->paymentRow(UuidBinary::toString($row->id));
        $this->assertSame('SUCCESSFUL', $this->paymentStatusCode($fresh));
        $this->assertSame(1, (int) $fresh->requires_reconciliation);
        $this->assertSame('CURRENCY_MISMATCH', $fresh->reconciliation_reason_code);
    }

    public function test_expired_hold_still_marks_successful_but_flags_reconciliation(): void
    {
        ['row' => $row] = $this->createPendingPayment();

        // expires_at > held_at is a DB CHECK constraint
        // (chk_appointment_holds_expiration), so an expired-but-valid hold
        // must move both timestamps into the past off one captured $now
        // rather than just backdating expires_at against the original
        // (much newer) held_at.
        $now = now();

        DB::table('appointment_holds')->where('id', $row->appointment_hold_id)->update([
            'held_at' => $now->copy()->subMinutes(20)->format('Y-m-d H:i:s.u'),
            'expires_at' => $now->copy()->subMinutes(10)->format('Y-m-d H:i:s.u'),
        ]);

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $row->requested_amount,
        ]))->assertStatus(200);

        $fresh = $this->paymentRow(UuidBinary::toString($row->id));
        $this->assertSame('SUCCESSFUL', $this->paymentStatusCode($fresh));
        $this->assertSame(1, (int) $fresh->requires_reconciliation);
        $this->assertSame('HOLD_EXPIRED', $fresh->reconciliation_reason_code);
        $this->assertSame(0, DB::table('bookings')->count());
    }

    public function test_released_hold_still_marks_successful_but_flags_reconciliation(): void
    {
        ['row' => $row] = $this->createPendingPayment();

        DB::table('appointment_holds')->where('id', $row->appointment_hold_id)->update([
            'released_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $row->requested_amount,
        ]))->assertStatus(200);

        $fresh = $this->paymentRow(UuidBinary::toString($row->id));
        $this->assertSame('SUCCESSFUL', $this->paymentStatusCode($fresh));
        $this->assertSame(1, (int) $fresh->requires_reconciliation);
        $this->assertSame('HOLD_RELEASED', $fresh->reconciliation_reason_code);
    }

    public function test_successful_payment_leaves_cart_in_checkout_status(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $cartUuid = $this->getCheckout($customer['access_token'])->json('data.checkout.cart_uuid');
        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($response->json('data.payment.uuid'));

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $row->requested_amount,
        ]))->assertStatus(200);

        // Phase 6A never converts the cart on success - that belongs to
        // Phase 7's atomic Booking creation from the paid snapshot.
        $this->assertSame('CHECKOUT', $this->cartStatusCode($cartUuid));
        $this->assertSame(0, DB::table('bookings')->count());
    }

    public function test_no_booking_is_ever_created_by_phase_6a(): void
    {
        ['row' => $row] = $this->createPendingPayment();

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $row->requested_amount,
        ]))->assertStatus(200);

        $this->assertSame(0, DB::table('bookings')->count());
        $this->assertSame(0, DB::table('booking_items')->count());
    }

    public function test_successful_cart_marker_backstops_a_second_successful_attempt_for_the_same_cart(): void
    {
        ['row' => $row] = $this->createPendingPayment();

        DB::table('payment_attempts')->where('id', $row->id)->update([
            'status_id' => DB::table('payment_statuses')->where('code', 'SUCCESSFUL')->value('id'),
            'confirmed_amount' => $row->requested_amount,
            'successful_at' => now()->format('Y-m-d H:i:s.u'),
            'finalized_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        $secondId = UuidBinary::toBinary(UuidBinary::generate());
        $now = now()->format('Y-m-d H:i:s.u');

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('payment_attempts')->insert([
            'id' => $secondId,
            'cart_id' => $row->cart_id,
            'appointment_hold_id' => $row->appointment_hold_id,
            'status_id' => DB::table('payment_statuses')->where('code', 'SUCCESSFUL')->value('id'),
            'currency_id' => $row->currency_id,
            'checkout_reference' => CheckoutReferenceGenerator::generate(),
            'idempotency_key' => hash('sha256', Str::uuid()->toString(), true),
            'provider_code' => 'FAKE',
            'requested_amount' => $row->requested_amount,
            'confirmed_amount' => $row->requested_amount,
            'checkout_snapshot' => $row->checkout_snapshot,
            'checkout_snapshot_hash' => $row->checkout_snapshot_hash,
            'successful_at' => $now,
            'finalized_at' => $now,
            'status_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
