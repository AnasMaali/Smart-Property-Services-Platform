<?php

namespace Tests\Feature\Admin;

use App\Support\Payment\Gateway\PaymentCreationResult;
use App\Support\Payment\Gateway\RefundCreationResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\Feature\Admin\Concerns\CreatesFinancialFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Admin Financial Dashboard - GET /v1/admin/financial-dashboard
 * (App\Actions\Admin\Financial\AdminGetFinancialDashboardAction /
 * App\Support\Admin\AdminFinancialSummaryCalculator). Every money source
 * this covers is documented in that Calculator's own class docblock.
 */
class AdminFinancialDashboardTest extends TestCase
{
    use CreatesAdminFixtures;
    use CreatesFinancialFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dashboard(string $accessToken, array $query = []): TestResponse
    {
        $suffix = $query === [] ? '' : ('?'.http_build_query($query));

        return $this->getJson('/api/v1/admin/financial-dashboard'.$suffix, $this->bearer($accessToken));
    }

    // -----------------------------------------------------------------
    // Auth
    // -----------------------------------------------------------------

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->getJson('/api/v1/admin/financial-dashboard')->assertStatus(401);
    }

    public function test_customer_is_denied(): void
    {
        $fixture = $this->successfulPayment();

        $this->getJson('/api/v1/admin/financial-dashboard', ['Authorization' => 'Bearer '.$fixture['customer']['access_token']])
            ->assertStatus(401);
    }

    public function test_admin_with_payments_view_can_read_the_dashboard(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->dashboard($admin['access_token'])->assertStatus(200)->assertJson(['success' => true]);
    }

    // -----------------------------------------------------------------
    // Zero state
    // -----------------------------------------------------------------

    public function test_zero_state_dashboard_returns_zero_safely(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->dashboard($admin['access_token'], ['range' => 'THIS_MONTH']);

        $response->assertStatus(200);
        $this->assertSame('0.000000', $response->json('data.summary.gross_revenue'));
        $this->assertSame('0.000000', $response->json('data.summary.refunds'));
        $this->assertSame('0.000000', $response->json('data.summary.net_revenue'));
        $this->assertSame('0.000000', $response->json('data.summary.breakdown.credit_card'));
        $this->assertSame('0.000000', $response->json('data.summary.breakdown.apple_pay'));
        $this->assertSame('0.000000', $response->json('data.summary.breakdown.pay_on_site.collected'));
        $this->assertSame('0.000000', $response->json('data.summary.breakdown.pay_on_site.pending'));
        $this->assertSame('AED', $response->json('data.summary.currency.code'));
    }

    // -----------------------------------------------------------------
    // Card / Apple Pay
    // -----------------------------------------------------------------

    public function test_successful_card_payment_contributes_to_gross_and_card_breakdown(): void
    {
        $fixture = $this->successfulPayment();
        $amount = (string) $fixture['payment']->confirmed_amount;
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->dashboard($admin['access_token'], ['range' => 'THIS_MONTH']);

        $this->assertSame($amount, $response->json('data.summary.gross_revenue'));
        $this->assertSame($amount, $response->json('data.summary.breakdown.credit_card'));
        $this->assertSame('0.000000', $response->json('data.summary.breakdown.apple_pay'));
        $this->assertSame(1, $response->json('data.summary.bookings.paid_count'));
    }

    public function test_successful_apple_pay_payment_contributes_to_gross_and_apple_pay_breakdown(): void
    {
        $fixture = $this->successfulApplePayPayment();
        $amount = (string) $fixture['payment']->confirmed_amount;
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->dashboard($admin['access_token'], ['range' => 'THIS_MONTH']);

        $this->assertSame($amount, $response->json('data.summary.gross_revenue'));
        $this->assertSame($amount, $response->json('data.summary.breakdown.apple_pay'));
        $this->assertSame('0.000000', $response->json('data.summary.breakdown.credit_card'));
    }

    public function test_pending_payment_does_not_count(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $this->createPayment($customer['access_token'], (string) Str::uuid());
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->dashboard($admin['access_token'], ['range' => 'THIS_MONTH']);

        $this->assertSame('0.000000', $response->json('data.summary.gross_revenue'));
    }

    public function test_failed_payment_does_not_count(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $this->fakeGateway()->queueNextCreation(PaymentCreationResult::definitiveFailure('BAD_PARAMS', 'invalid'));
        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $this->assertSame('FAILED', $response->json('data.payment.status'));

        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $dashboardResponse = $this->dashboard($admin['access_token'], ['range' => 'THIS_MONTH']);

        $this->assertSame('0.000000', $dashboardResponse->json('data.summary.gross_revenue'));
    }

    // -----------------------------------------------------------------
    // Pay on Site
    // -----------------------------------------------------------------

    public function test_pending_pay_on_site_booking_shows_only_in_pending(): void
    {
        $this->pendingPayOnSiteBooking();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->dashboard($admin['access_token'], ['range' => 'THIS_MONTH']);

        $this->assertSame('0.000000', $response->json('data.summary.gross_revenue'));
        $this->assertSame('0.000000', $response->json('data.summary.breakdown.pay_on_site.collected'));
        $this->assertSame('100.000000', $response->json('data.summary.breakdown.pay_on_site.pending'));
        $this->assertSame(1, $response->json('data.summary.bookings.pay_on_site_pending_count'));
    }

    public function test_collected_pay_on_site_moves_to_collected_revenue_and_out_of_pending(): void
    {
        $this->collectedPayOnSiteBooking();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->dashboard($admin['access_token'], ['range' => 'THIS_MONTH']);

        $this->assertSame('100.000000', $response->json('data.summary.gross_revenue'));
        $this->assertSame('100.000000', $response->json('data.summary.breakdown.pay_on_site.collected'));
        $this->assertSame('0.000000', $response->json('data.summary.breakdown.pay_on_site.pending'));
        $this->assertSame(0, $response->json('data.summary.bookings.pay_on_site_pending_count'));
    }

    public function test_collected_pay_on_site_is_never_double_counted_on_repeat_calls(): void
    {
        $collected = $this->collectedPayOnSiteBooking();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        // A second (idempotent no-op) collection call must not create a
        // second settlement row or inflate revenue.
        $this->postJson(
            '/api/v1/admin/bookings/'.UuidBinary::toString($collected['booking']->id).'/collect-on-site-payment',
            [],
            $this->bearer($collected['admin']['access_token'])
        )->assertStatus(200);

        $response = $this->dashboard($admin['access_token'], ['range' => 'THIS_MONTH']);
        $this->assertSame('100.000000', $response->json('data.summary.gross_revenue'));
        $this->assertSame('100.000000', $response->json('data.summary.breakdown.pay_on_site.collected'));
    }

    // -----------------------------------------------------------------
    // Refunds
    // -----------------------------------------------------------------

    public function test_successful_refund_reduces_net_revenue(): void
    {
        $refund = $this->succeededRefund();
        $this->assertSame('SUCCEEDED', DB::table('booking_refund_statuses')->where('id', $refund['refund']->status_id)->value('code'));

        $gross = (string) $refund['payment']->confirmed_amount;
        $refundedAmount = (string) $refund['refund']->requested_amount;
        $expectedNet = bcsub($gross, $refundedAmount, 6);

        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $response = $this->dashboard($admin['access_token'], ['range' => 'THIS_MONTH']);

        $this->assertSame($gross, $response->json('data.summary.gross_revenue'));
        $this->assertSame($refundedAmount, $response->json('data.summary.refunds'));
        $this->assertSame($expectedNet, $response->json('data.summary.net_revenue'));
        $this->assertSame(1, $response->json('data.summary.bookings.refunded_count'));
    }

    public function test_pending_refund_does_not_reduce_net_revenue(): void
    {
        config(['cancellation.timezone' => 'UTC']);

        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment(['starts_at' => now()->addDays(2)]);
        $booking = $this->bookingRowForPayment($payment);

        $this->fakeGateway()->queueNextRefund(RefundCreationResult::unknown('simulated timeout'));
        $this->postJson('/api/v1/bookings/'.UuidBinary::toString($booking->id).'/cancel', [], ['Authorization' => 'Bearer '.$customer['access_token']])
            ->assertStatus(200);

        $refund = $this->bookingRefundRow($booking);
        $this->assertSame('PENDING', $this->bookingRefundStatusCode($refund));

        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $response = $this->dashboard($admin['access_token'], ['range' => 'THIS_MONTH']);

        $this->assertSame('0.000000', $response->json('data.summary.refunds'));
        $this->assertSame((string) $payment->confirmed_amount, $response->json('data.summary.net_revenue'));
    }

    // -----------------------------------------------------------------
    // Repair Quote balance payments
    // -----------------------------------------------------------------

    public function test_repair_quote_balance_payment_contributes_to_gross_without_double_counting_credit(): void
    {
        $fixture = $this->succeededRepairQuoteBalancePayment('150.000000', '1000');
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->dashboard($admin['access_token'], ['range' => 'THIS_MONTH']);

        // 150 (inspection) + 850 (balance) = 1000, never 150 + 1000.
        $this->assertSame('1000.000000', $response->json('data.summary.gross_revenue'));
        $this->assertSame('850.000000', $response->json('data.summary.repair_quote_balance_collected'));
        $this->assertSame('1000.000000', $response->json('data.summary.breakdown.credit_card'));
    }

    // -----------------------------------------------------------------
    // Date ranges
    // -----------------------------------------------------------------

    /**
     * Backdates a successful payment's `successful_at` (and every other
     * timestamp column BLUE's own CHECK constraints require to stay
     * consistent with it) to an arbitrary UTC instant, without freezing
     * `Carbon::now()` - freezing time before creating the fixture's own
     * customer/admin auth tokens is unsafe here (JWT `iat`/`exp` are
     * signed against the real wall clock, not `Carbon::setTestNow()`, so
     * a frozen "now" that drifts from real time makes a freshly issued
     * token look already expired/not-yet-valid). Every other column on
     * this fixture's Booking/Cart/etc. is left at its real creation time -
     * irrelevant to what AdminFinancialSummaryCalculator reads.
     */
    private function backdatePaymentSuccessfulAt(object $payment, Carbon $to): void
    {
        $timestamp = $to->format('Y-m-d H:i:s.u');

        DB::table('payment_attempts')->where('id', $payment->id)->update([
            'created_at' => $timestamp,
            'status_changed_at' => $timestamp,
            'successful_at' => $timestamp,
            'finalized_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    public function test_custom_date_range_excludes_payments_outside_the_window(): void
    {
        $fixture = $this->successfulPayment();
        $amount = (string) $fixture['payment']->confirmed_amount;

        $this->backdatePaymentSuccessfulAt($fixture['payment'], Carbon::now('Asia/Dubai')->subDays(10));

        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $elevenDaysAgo = Carbon::now('Asia/Dubai')->subDays(11)->toDateString();
        $nineDaysAgo = Carbon::now('Asia/Dubai')->subDays(9)->toDateString();
        $inRange = $this->dashboard($admin['access_token'], ['range' => 'CUSTOM', 'from' => $elevenDaysAgo, 'to' => $nineDaysAgo]);
        $this->assertSame($amount, $inRange->json('data.summary.gross_revenue'));

        $thirtyDaysAgo = Carbon::now('Asia/Dubai')->subDays(30)->toDateString();
        $twentyDaysAgo = Carbon::now('Asia/Dubai')->subDays(20)->toDateString();
        $outOfRange = $this->dashboard($admin['access_token'], ['range' => 'CUSTOM', 'from' => $thirtyDaysAgo, 'to' => $twentyDaysAgo]);
        $this->assertSame('0.000000', $outOfRange->json('data.summary.gross_revenue'));
    }

    public function test_custom_range_requires_both_from_and_to(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->dashboard($admin['access_token'], ['range' => 'CUSTOM', 'from' => '2026-09-09'])
            ->assertStatus(422);
    }

    /**
     * A payment whose `successful_at` UTC calendar date differs from
     * "now"'s own UTC calendar date, but shares the SAME UAE (Asia/Dubai)
     * calendar date, must still appear under `range=TODAY` - proves
     * App\Support\Admin\AdminFinancialDateRange resolves "today" against
     * `config('finance.timezone')`, never a naive UTC `startOfDay()`.
     * Dubai is UTC+4, so Dubai midnight is always 20:00 UTC on the
     * PREVIOUS UTC calendar date - backdating to 30 minutes past Dubai
     * midnight reliably lands on the UTC-previous-day/Dubai-same-day
     * split this test exists to prove, on every real calendar day this
     * suite ever runs.
     */
    public function test_today_range_uses_uae_calendar_day_not_utc(): void
    {
        $fixture = $this->successfulPayment();
        $amount = (string) $fixture['payment']->confirmed_amount;

        $dubaiTodayStart = Carbon::now('Asia/Dubai')->startOfDay();
        $this->backdatePaymentSuccessfulAt($fixture['payment'], $dubaiTodayStart->clone()->addMinutes(30)->setTimezone('UTC'));

        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $response = $this->dashboard($admin['access_token'], ['range' => 'TODAY']);

        $this->assertSame($amount, $response->json('data.summary.gross_revenue'));
    }

    // -----------------------------------------------------------------
    // Historical safety
    // -----------------------------------------------------------------

    public function test_changing_service_price_after_payment_does_not_change_reported_revenue(): void
    {
        $fixture = $this->successfulPayment();
        $amount = (string) $fixture['payment']->confirmed_amount;
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $before = $this->dashboard($admin['access_token'], ['range' => 'THIS_MONTH']);
        $this->assertSame($amount, $before->json('data.summary.gross_revenue'));

        DB::table('services')->update(['original_price' => '999999.000000']);

        $after = $this->dashboard($admin['access_token'], ['range' => 'THIS_MONTH']);
        $this->assertSame($amount, $after->json('data.summary.gross_revenue'));
    }
}
