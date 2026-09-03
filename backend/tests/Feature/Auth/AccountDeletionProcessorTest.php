<?php

namespace Tests\Feature\Auth;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\Support\DeletesCustomerAccountsForTests;
use Tests\TestCase;

/**
 * accounts:process-pending-deletions (App\Console\Commands\
 * ProcessPendingAccountDeletions) - the scheduled completion path for a
 * deferred account deletion. Proves it reuses the exact same
 * App\Support\Auth\AccountDeletionEligibilityChecker /
 * App\Actions\Auth\AccountDeletionExecutor the HTTP-facing
 * DeleteAccountAction uses (same tombstoning, same session revocation,
 * same OTP invalidation, same historical-data preservation - never a
 * second, parallel erasure implementation), completing automatically once
 * the customer's blocking obligation reaches a terminal state.
 */
class AccountDeletionProcessorTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;
    use DeletesCustomerAccountsForTests;

    private const FIXTURE_PASSWORD = 'CartTestPassw0rd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCartFixtures();
    }

    private function userRow(string $userUuid): ?object
    {
        return DB::table('users')->where('id', UuidBinary::toBinary($userUuid))->first();
    }

    private function accountStatusCode(object $userRow): string
    {
        return DB::table('user_account_statuses')->where('id', $userRow->account_status_id)->value('code');
    }

    private function requestRow(string $userUuid): ?object
    {
        return DB::table('customer_account_deletion_requests')
            ->where('user_id', UuidBinary::toBinary($userUuid))
            ->first();
    }

    // ================================================================
    // Completes once the blocking obligation becomes terminal
    // ================================================================

    public function test_processor_completes_deletion_once_booking_becomes_terminal(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $this->deleteAccount($customer['access_token'])->assertStatus(202);

        $this->postJson('/api/v1/bookings/'.UuidBinary::toString($booking->id).'/cancel', [], [
            'Authorization' => 'Bearer '.$customer['access_token'],
        ])->assertStatus(200);

        $this->artisan('accounts:process-pending-deletions')->assertSuccessful();

        $row = $this->userRow($customer['user_uuid']);
        $this->assertSame('DEACTIVATED', $this->accountStatusCode($row));
        $this->assertNotNull($row->deleted_at);
        $this->assertNotNull($this->requestRow($customer['user_uuid'])->completed_at);
    }

    public function test_processor_completes_deletion_once_payment_becomes_resolved(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($response->json('data.payment.uuid'));

        $this->deleteAccount($customer['access_token'])->assertStatus(202);

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'CANCELED',
            'provider_status_code' => 'canceled',
        ]))->assertStatus(200);

        $this->artisan('accounts:process-pending-deletions')->assertSuccessful();

        $userRow = $this->userRow($customer['user_uuid']);
        $this->assertSame('DEACTIVATED', $this->accountStatusCode($userRow));
        $this->assertNotNull($userRow->deleted_at);
    }

    public function test_processor_completes_deletion_once_contract_becomes_terminal(): void
    {
        $fixture = $this->activeContractWithItem();
        $customer = $fixture['customer'];

        $this->deleteAccount($customer['access_token'])->assertStatus(202);

        // BLUE V1 Phase A2.5 - contracts.cancel now also requires a fresh
        // WebAuthn step-up; grant it directly rather than driving the real
        // ceremony, exactly like activeContractWithItem() itself mints the
        // Admin session directly instead of via HTTP.
        $this->markStepUpVerified($fixture['admin']['session_uuid']);

        $this->adminCancelContract($fixture['admin']['access_token'], UuidBinary::toString($fixture['contract']->id))
            ->assertStatus(200);

        $this->artisan('accounts:process-pending-deletions')->assertSuccessful();

        $userRow = $this->userRow($customer['user_uuid']);
        $this->assertSame('DEACTIVATED', $this->accountStatusCode($userRow));
        $this->assertNotNull($userRow->deleted_at);
    }

    // ================================================================
    // Reuses the exact existing erasure lifecycle (same effects as the
    // HTTP-immediate path in DeleteAccountTest)
    // ================================================================

    private function completedViaProcessor(): array
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $originalPhone = DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->value('phone_number');
        $originalEmail = DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->value('email');

        $this->deleteAccount($customer['access_token'])->assertStatus(202);

        $this->postJson('/api/v1/bookings/'.UuidBinary::toString($booking->id).'/cancel', [], [
            'Authorization' => 'Bearer '.$customer['access_token'],
        ])->assertStatus(200);

        $this->artisan('accounts:process-pending-deletions')->assertSuccessful();

        return ['customer' => $customer, 'original_phone' => $originalPhone, 'original_email' => $originalEmail];
    }

    public function test_processor_completion_revokes_all_sessions(): void
    {
        $result = $this->completedViaProcessor();

        $userIdBinary = UuidBinary::toBinary($result['customer']['user_uuid']);
        $total = DB::table('auth_sessions')->where('user_id', $userIdBinary)->count();
        $revoked = DB::table('auth_sessions')->where('user_id', $userIdBinary)->whereNotNull('revoked_at')->count();

        $this->assertGreaterThan(0, $total);
        $this->assertSame($total, $revoked);

        $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$result['customer']['access_token']])
            ->assertStatus(401);
    }

    public function test_processor_completion_removes_customer_role(): void
    {
        $result = $this->completedViaProcessor();

        $hasCustomerRole = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', UuidBinary::toBinary($result['customer']['user_uuid']))
            ->where('roles.code', 'CUSTOMER')
            ->exists();

        $this->assertFalse($hasCustomerRole);
    }

    public function test_processor_completion_tombstones_phone_and_email(): void
    {
        $result = $this->completedViaProcessor();

        $row = $this->userRow($result['customer']['user_uuid']);
        $this->assertNotSame($result['original_phone'], $row->phone_number);
        $this->assertNotSame($result['original_email'], $row->email);
        $this->assertStringStartsWith('DEL', $row->phone_number);
        $this->assertStringContainsString('@deleted.invalid', $row->email);
    }

    public function test_processor_completion_releases_original_phone_and_email_for_reuse(): void
    {
        $result = $this->completedViaProcessor();

        $dubaiCityId = (int) DB::table('cities')->where('code', 'DUBAI')->value('id');
        $dubaiAreaId = (int) DB::table('areas')->where('city_id', $dubaiCityId)->value('id');
        $propertyRelationshipTypeId = (int) DB::table('property_relationship_types')->value('id');
        $serviceCategoryId = (int) DB::table('service_categories')->value('id');

        $this->postJson('/api/v1/auth/register', [
            'full_name' => 'Reused After Processor',
            'phone_number' => $result['original_phone'],
            'email' => $result['original_email'],
            'password' => 'BrandNewPassw0rd',
            'city_id' => $dubaiCityId,
            'area_id' => $dubaiAreaId,
            'property_relationship_type_id' => $propertyRelationshipTypeId,
            'service_interests' => [$serviceCategoryId],
        ])->assertStatus(201);
    }

    public function test_processor_completion_invalidates_pending_otps(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $this->postJson('/api/v1/auth/change-phone-number', [
            'new_phone_number' => '+971509'.random_int(100000, 999999),
        ], ['Authorization' => 'Bearer '.$customer['access_token']])->assertStatus(200);

        $this->deleteAccount($customer['access_token'])->assertStatus(202);

        $this->postJson('/api/v1/bookings/'.UuidBinary::toString($booking->id).'/cancel', [], [
            'Authorization' => 'Bearer '.$customer['access_token'],
        ])->assertStatus(200);

        $this->artisan('accounts:process-pending-deletions')->assertSuccessful();

        $pendingCount = DB::table('otp_verifications')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->where('status_id', DB::table('otp_verification_statuses')->where('code', 'PENDING')->value('id'))
            ->count();

        $this->assertSame(0, $pendingCount);
    }

    public function test_processor_completion_preserves_historical_financial_data(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $this->deleteAccount($customer['access_token'])->assertStatus(202);

        $this->postJson('/api/v1/bookings/'.UuidBinary::toString($booking->id).'/cancel', [], [
            'Authorization' => 'Bearer '.$customer['access_token'],
        ])->assertStatus(200);

        $this->artisan('accounts:process-pending-deletions')->assertSuccessful();

        $paymentAfter = DB::table('payment_attempts')->where('id', $payment->id)->first();
        $this->assertNotNull($paymentAfter);
        $this->assertSame($payment->checkout_snapshot, $paymentAfter->checkout_snapshot);
        $this->assertSame($payment->checkout_snapshot_hash, $paymentAfter->checkout_snapshot_hash);
        $this->assertSame('SUCCESSFUL', DB::table('payment_statuses')->where('id', $paymentAfter->status_id)->value('code'));

        $bookingAfter = $this->bookingRow(UuidBinary::toString($booking->id));
        $this->assertSame('CANCELLED', DB::table('booking_statuses')->where('id', $bookingAfter->status_id)->value('code'));
    }

    // ================================================================
    // Idempotency / atomicity / provider isolation
    // ================================================================

    public function test_processor_is_idempotent_after_completion(): void
    {
        $result = $this->completedViaProcessor();
        $requestBefore = $this->requestRow($result['customer']['user_uuid']);

        $this->artisan('accounts:process-pending-deletions')->assertSuccessful();

        $requestAfter = $this->requestRow($result['customer']['user_uuid']);
        $this->assertSame((string) $requestBefore->completed_at, (string) $requestAfter->completed_at);
    }

    public function test_completed_at_is_set_exactly_once_and_remains_stable(): void
    {
        $result = $this->completedViaProcessor();

        $countCompleted = DB::table('customer_account_deletion_requests')
            ->where('user_id', UuidBinary::toBinary($result['customer']['user_uuid']))
            ->whereNotNull('completed_at')
            ->count();

        $this->assertSame(1, $countCompleted);
    }

    public function test_a_customer_still_pending_leaves_completed_at_null_and_updates_last_checked_at(): void
    {
        ['customer' => $customer] = $this->successfulPayment();
        $this->deleteAccount($customer['access_token'])->assertStatus(202);

        $this->artisan('accounts:process-pending-deletions')->assertSuccessful();

        $request = $this->requestRow($customer['user_uuid']);
        $this->assertNull($request->completed_at);
        $this->assertNotNull($request->last_checked_at);
    }

    public function test_an_admin_role_granted_after_queuing_leaves_the_request_pending_forever_rather_than_completing(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $this->deleteAccount($customer['access_token'])->assertStatus(202);

        $this->postJson('/api/v1/bookings/'.UuidBinary::toString($booking->id).'/cancel', [], [
            'Authorization' => 'Bearer '.$customer['access_token'],
        ])->assertStatus(200);

        DB::table('user_roles')->insert([
            'user_id' => UuidBinary::toBinary($customer['user_uuid']),
            'role_id' => (int) DB::table('roles')->where('code', 'ADMIN')->value('id'),
            'assigned_by_user_id' => null,
            'assigned_at' => now(),
        ]);

        $this->artisan('accounts:process-pending-deletions')->assertSuccessful();

        $userRow = $this->userRow($customer['user_uuid']);
        $this->assertSame('ACTIVE', $this->accountStatusCode($userRow));
        $this->assertNull($userRow->deleted_at);
        $this->assertNull($this->requestRow($customer['user_uuid'])->completed_at);
    }

    public function test_processor_failure_rolls_back_that_customers_deletion_atomically(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $this->deleteAccount($customer['access_token'])->assertStatus(202);

        $this->postJson('/api/v1/bookings/'.UuidBinary::toString($booking->id).'/cancel', [], [
            'Authorization' => 'Bearer '.$customer['access_token'],
        ])->assertStatus(200);

        $originalFullName = DB::table('user_profiles')->where('user_id', UuidBinary::toBinary($customer['user_uuid']))->value('full_name');

        // Force AccountDeletionExecutor's DEACTIVATED lookup to fail
        // partway through, exactly like DeleteAccountTest's own atomicity
        // proof, but invoked through the console command's per-candidate
        // transaction instead of the HTTP Action.
        DB::table('user_account_statuses')->where('code', 'DEACTIVATED')->update(['code' => 'DEACTIVATED_TEMP_RENAMED']);

        try {
            $this->artisan('accounts:process-pending-deletions')->assertSuccessful();
        } finally {
            DB::table('user_account_statuses')->where('code', 'DEACTIVATED_TEMP_RENAMED')->update(['code' => 'DEACTIVATED']);
        }

        $userRow = $this->userRow($customer['user_uuid']);
        $this->assertSame('ACTIVE', $this->accountStatusCode($userRow));
        $this->assertNull($userRow->deleted_at);

        $fullNameAfter = DB::table('user_profiles')->where('user_id', UuidBinary::toBinary($customer['user_uuid']))->value('full_name');
        $this->assertSame($originalFullName, $fullNameAfter);

        // The command itself must still exit successfully - one
        // candidate's failure is caught and reported, never fatal to the
        // whole run (see the command's own per-candidate try/catch).
        $this->assertNull($this->requestRow($customer['user_uuid'])->completed_at);
    }

    public function test_processor_makes_no_outbound_provider_http_call(): void
    {
        Http::fake();

        $result = $this->completedViaProcessor();

        Http::assertNothingSent();
        unset($result);
    }

    public function test_processor_batch_limit_option_is_respected(): void
    {
        ['customer' => $customerA] = $this->successfulPayment();
        $this->deleteAccount($customerA['access_token'])->assertStatus(202);

        // Distinct slot period - successfulPayment()'s default appointment
        // slot (tomorrow, same fixed 2-hour window) would otherwise collide
        // with customer A's own slot on uq_appointment_slots_period.
        ['customer' => $customerB] = $this->successfulPayment(['starts_at' => now()->addDays(2)]);
        $this->deleteAccount($customerB['access_token'])->assertStatus(202);

        $this->artisan('accounts:process-pending-deletions', ['--limit' => 1])->assertSuccessful();

        $touched = DB::table('customer_account_deletion_requests')->whereNotNull('last_checked_at')->count();
        $this->assertSame(1, $touched);
    }
}
