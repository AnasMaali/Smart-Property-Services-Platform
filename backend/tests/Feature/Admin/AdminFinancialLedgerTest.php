<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\Feature\Admin\Concerns\CreatesFinancialFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Admin Financial Ledger - GET /v1/admin/financial-ledger
 * (App\Actions\Admin\Financial\AdminListFinancialLedgerAction /
 * App\Support\Admin\AdminFinancialLedgerPresenter). A read-only UNION over
 * the same authoritative tables App\Support\Admin\
 * AdminFinancialSummaryCalculator sums - see that class's docblock for the
 * full source-of-truth map.
 */
class AdminFinancialLedgerTest extends TestCase
{
    use CreatesAdminFixtures;
    use CreatesFinancialFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    private function ledger(string $accessToken, array $query = []): TestResponse
    {
        $suffix = $query === [] ? '' : ('?'.http_build_query($query));

        return $this->getJson('/api/v1/admin/financial-ledger'.$suffix, $this->bearer($accessToken));
    }

    // -----------------------------------------------------------------
    // Auth
    // -----------------------------------------------------------------

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->getJson('/api/v1/admin/financial-ledger')->assertStatus(401);
    }

    public function test_customer_is_denied(): void
    {
        $fixture = $this->successfulPayment();

        $this->getJson('/api/v1/admin/financial-ledger', ['Authorization' => 'Bearer '.$fixture['customer']['access_token']])
            ->assertStatus(401);
    }

    public function test_admin_with_payments_view_can_read_the_ledger(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->ledger($admin['access_token'])->assertStatus(200)->assertJson(['success' => true]);
    }

    // -----------------------------------------------------------------
    // Zero state / pagination shape
    // -----------------------------------------------------------------

    public function test_zero_state_returns_an_empty_page_safely(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->ledger($admin['access_token']);

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.entries'));
        $this->assertSame(0, $response->json('data.pagination.total'));
    }

    // -----------------------------------------------------------------
    // Event types appear correctly
    // -----------------------------------------------------------------

    public function test_successful_card_payment_appears_as_a_credit_entry(): void
    {
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->ledger($admin['access_token']);

        $entry = collect($response->json('data.entries'))->firstWhere('event_type', 'CARD_PAYMENT');
        $this->assertNotNull($entry);
        $this->assertSame('CREDIT', $entry['direction']);
        $this->assertSame('CARD', $entry['payment_method']);
        $this->assertSame((string) $fixture['payment']->confirmed_amount, $entry['amount']);
        $this->assertSame('AED', $entry['currency']['code']);
        $this->assertSame(UuidBinary::toString($booking->id), $entry['booking']['uuid']);
        $this->assertSame($booking->booking_number, $entry['booking']['booking_number']);
        $this->assertNotNull($entry['customer']['full_name']);
        $this->assertSame(UuidBinary::toString($fixture['payment']->id), $entry['entry_reference']);
    }

    public function test_successful_apple_pay_payment_appears_as_a_credit_entry(): void
    {
        $this->successfulApplePayPayment();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->ledger($admin['access_token']);

        $entry = collect($response->json('data.entries'))->firstWhere('event_type', 'APPLE_PAY_PAYMENT');
        $this->assertNotNull($entry);
        $this->assertSame('CREDIT', $entry['direction']);
        $this->assertSame('APPLE_PAY', $entry['payment_method']);
    }

    public function test_pay_on_site_collection_appears_as_a_credit_entry(): void
    {
        $collected = $this->collectedPayOnSiteBooking();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->ledger($admin['access_token']);

        $entry = collect($response->json('data.entries'))->firstWhere('event_type', 'PAY_ON_SITE_COLLECTION');
        $this->assertNotNull($entry);
        $this->assertSame('CREDIT', $entry['direction']);
        $this->assertSame('PAY_ON_SITE', $entry['payment_method']);
        $this->assertSame('100.000000', $entry['amount']);
        $this->assertSame(UuidBinary::toString($collected['booking']->id), $entry['booking']['uuid']);
    }

    public function test_pending_pay_on_site_booking_produces_no_ledger_entry(): void
    {
        $this->pendingPayOnSiteBooking();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->ledger($admin['access_token']);

        $this->assertSame([], $response->json('data.entries'));
    }

    public function test_refund_appears_as_a_debit_entry(): void
    {
        $refund = $this->succeededRefund();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->ledger($admin['access_token']);

        $entry = collect($response->json('data.entries'))->firstWhere('event_type', 'REFUND');
        $this->assertNotNull($entry);
        $this->assertSame('DEBIT', $entry['direction']);
        $this->assertSame((string) $refund['refund']->requested_amount, $entry['amount']);
        $this->assertSame(UuidBinary::toString($refund['booking']->id), $entry['booking']['uuid']);
    }

    public function test_repair_quote_balance_payment_appears_as_a_credit_entry(): void
    {
        $fixture = $this->succeededRepairQuoteBalancePayment('150.000000', '1000');
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->ledger($admin['access_token']);

        $entries = collect($response->json('data.entries'))->where('event_type', 'REPAIR_QUOTE_BALANCE_PAYMENT');
        $this->assertCount(1, $entries);
        $entry = $entries->first();
        $this->assertSame('CREDIT', $entry['direction']);
        $this->assertSame('850.000000', $entry['amount']);
        $this->assertSame(UuidBinary::toString($fixture['booking']->id), $entry['booking']['uuid']);

        // The original inspection payment is a SEPARATE CARD_PAYMENT entry -
        // never merged with the balance payment, and never double counted.
        $cardEntries = collect($response->json('data.entries'))->where('event_type', 'CARD_PAYMENT');
        $this->assertCount(1, $cardEntries);
        $this->assertSame('150.000000', $cardEntries->first()['amount']);
    }

    // -----------------------------------------------------------------
    // Chronological order
    // -----------------------------------------------------------------

    public function test_entries_are_returned_in_reverse_chronological_order(): void
    {
        $first = $this->successfulPayment();
        $second = $this->successfulApplePayPayment();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->ledger($admin['access_token']);
        $occurredAt = collect($response->json('data.entries'))->pluck('occurred_at')->all();

        $sorted = collect($occurredAt)->sortDesc()->values()->all();
        $this->assertSame($sorted, $occurredAt);

        $references = collect($response->json('data.entries'))->pluck('entry_reference')->all();
        $this->assertContains(UuidBinary::toString($first['payment']->id), $references);
        $this->assertContains(UuidBinary::toString($second['payment']->id), $references);
    }

    // -----------------------------------------------------------------
    // Filters
    // -----------------------------------------------------------------

    public function test_event_type_filter_only_returns_matching_entries(): void
    {
        $this->successfulPayment();
        $this->successfulApplePayPayment();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->ledger($admin['access_token'], ['event_type' => 'APPLE_PAY_PAYMENT']);

        $entries = collect($response->json('data.entries'));
        $this->assertGreaterThanOrEqual(1, $entries->count());
        $this->assertTrue($entries->every(fn ($entry) => $entry['event_type'] === 'APPLE_PAY_PAYMENT'));
    }

    public function test_direction_filter_only_returns_matching_entries(): void
    {
        $this->succeededRefund();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->ledger($admin['access_token'], ['direction' => 'DEBIT']);

        $entries = collect($response->json('data.entries'));
        $this->assertGreaterThanOrEqual(1, $entries->count());
        $this->assertTrue($entries->every(fn ($entry) => $entry['direction'] === 'DEBIT'));
    }

    public function test_payment_method_filter_only_returns_matching_entries(): void
    {
        $this->successfulPayment();
        $this->collectedPayOnSiteBooking();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->ledger($admin['access_token'], ['payment_method' => 'PAY_ON_SITE']);

        $entries = collect($response->json('data.entries'));
        $this->assertGreaterThanOrEqual(1, $entries->count());
        $this->assertTrue($entries->every(fn ($entry) => $entry['payment_method'] === 'PAY_ON_SITE'));
    }

    public function test_booking_uuid_filter_only_returns_that_bookings_entries(): void
    {
        $fixture = $this->successfulPayment();
        $this->successfulApplePayPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->ledger($admin['access_token'], ['booking_uuid' => UuidBinary::toString($booking->id)]);

        $entries = collect($response->json('data.entries'));
        $this->assertCount(1, $entries);
        $this->assertSame(UuidBinary::toString($booking->id), $entries->first()['booking']['uuid']);
    }

    public function test_date_range_filter_excludes_entries_outside_the_window(): void
    {
        $fixture = $this->successfulPayment();

        $backdated = Carbon::now('Asia/Dubai')->subDays(10)->setTimezone('UTC')->format('Y-m-d H:i:s.u');
        DB::table('payment_attempts')->where('id', $fixture['payment']->id)->update([
            'created_at' => $backdated,
            'status_changed_at' => $backdated,
            'successful_at' => $backdated,
            'finalized_at' => $backdated,
            'updated_at' => $backdated,
        ]);

        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $outOfRange = $this->ledger($admin['access_token'], [
            'from' => Carbon::now('Asia/Dubai')->subDays(2)->toDateString(),
            'to' => Carbon::now('Asia/Dubai')->toDateString(),
        ]);
        $this->assertSame([], $outOfRange->json('data.entries'));

        $inRange = $this->ledger($admin['access_token'], [
            'from' => Carbon::now('Asia/Dubai')->subDays(11)->toDateString(),
            'to' => Carbon::now('Asia/Dubai')->subDays(9)->toDateString(),
        ]);
        $this->assertCount(1, $inRange->json('data.entries'));
    }

    public function test_pagination_shape_is_present(): void
    {
        $this->successfulPayment();
        $this->successfulApplePayPayment();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->ledger($admin['access_token'], ['per_page' => 1, 'page' => 1]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.entries'));
        $this->assertSame(1, $response->json('data.pagination.per_page'));
        $this->assertGreaterThanOrEqual(2, $response->json('data.pagination.total'));
        $this->assertGreaterThanOrEqual(2, $response->json('data.pagination.last_page'));
    }

    // -----------------------------------------------------------------
    // Safety
    // -----------------------------------------------------------------

    public function test_no_raw_binary_ids_or_secrets_are_exposed(): void
    {
        $this->successfulPayment();
        $this->collectedPayOnSiteBooking();
        $this->succeededRefund();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->ledger($admin['access_token']);
        $raw = $response->getContent();

        $this->assertStringNotContainsString('client_secret', $raw);
        $this->assertStringNotContainsString('checkout_snapshot', $raw);
        $this->assertStringNotContainsString('idempotency_key', $raw);

        foreach ($response->json('data.entries') as $entry) {
            // A raw binary(16) id, if ever leaked, is not valid UTF-8 and
            // json_encode would have already failed the request entirely -
            // this is a defense-in-depth structural check that every id-like
            // field is a well-formed UUID string instead.
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $entry['entry_reference']
            );

            if ($entry['booking'] !== null) {
                $this->assertMatchesRegularExpression(
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                    $entry['booking']['uuid']
                );
            }
        }
    }
}
