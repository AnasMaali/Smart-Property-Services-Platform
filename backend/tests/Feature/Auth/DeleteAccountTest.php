<?php

namespace Tests\Feature\Auth;

use App\Actions\Auth\DeleteAccountAction;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Real Apple App Store account deletion (App\Actions\Auth\DeleteAccountAction)
 * - never a status flip. Uses CreatesContractFixtures because it transitively
 * composes every fixture builder this suite needs (Cart/Checkout/Payment/
 * Booking via CreatesAdminFixtures -> CreatesTechnicianFixtures ->
 * CreatesBookingFixtures, and Property via CreatesPropertyFixtures) without
 * a second, parallel fixture-construction path.
 */
class DeleteAccountTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    // Every customer this fixture chain creates (createCartCustomer(), the
    // base of every higher-level builder used here) is given this exact
    // fixed password - see tests/Feature/Cart/Concerns/CreatesCartFixtures.
    private const FIXTURE_PASSWORD = 'CartTestPassw0rd';

    private const PENDING_MESSAGE = 'Account deletion has been requested and will complete automatically after your active bookings, contracts, or payments are resolved.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCartFixtures();
    }

    private function deleteAccount(string $accessToken, ?string $password = self::FIXTURE_PASSWORD)
    {
        return $this->deleteJson('/api/v1/auth/account', [
            'current_password' => $password,
        ], ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function userRow(string $userUuid): ?object
    {
        return DB::table('users')->where('id', UuidBinary::toBinary($userUuid))->first();
    }

    private function accountStatusCode(object $userRow): string
    {
        return DB::table('user_account_statuses')->where('id', $userRow->account_status_id)->value('code');
    }

    // ================================================================
    // AUTH / ENDPOINT
    // ================================================================

    public function test_unauthenticated_caller_cannot_delete_account(): void
    {
        $this->deleteJson('/api/v1/auth/account', ['current_password' => 'whatever'])
            ->assertStatus(401);
    }

    public function test_admin_token_cannot_use_the_customer_deletion_endpoint(): void
    {
        $admin = $this->createAndLoginAdmin();

        $this->deleteAccount($admin['access_token'])->assertStatus(401);
    }

    public function test_wrong_current_password_is_rejected_and_account_untouched(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->deleteAccount($customer['access_token'], 'DefinitelyWrongPassw0rd')
            ->assertStatus(422)
            ->assertJson(['success' => false, 'data' => null]);

        $row = $this->userRow($customer['user_uuid']);
        $this->assertSame('ACTIVE', $this->accountStatusCode($row));
        $this->assertNull($row->deleted_at);

        $this->assertSame(0, DB::table('customer_account_deletion_requests')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->count(), 'A wrong password must never create a deletion request.');
    }

    public function test_eligible_customer_with_correct_password_succeeds(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $response = $this->deleteAccount($customer['access_token']);

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'message' => 'Account deleted successfully.',
            'data' => null,
        ]);
    }

    public function test_deletion_never_targets_or_affects_another_customer(): void
    {
        $customerA = $this->createAuthenticatedCartCustomer();
        $customerB = $this->createAuthenticatedCartCustomer();

        $this->deleteAccount($customerA['access_token'])->assertStatus(200);

        $rowB = $this->userRow($customerB['user_uuid']);
        $this->assertSame('ACTIVE', $this->accountStatusCode($rowB));
        $this->assertNull($rowB->deleted_at);

        // customer B's own session is completely unaffected.
        $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$customerB['access_token']])
            ->assertStatus(200);
    }

    public function test_delete_route_is_protected_by_auth_customer_and_has_a_rate_limiter(): void
    {
        $route = collect(Route::getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v1/auth/account' && in_array('DELETE', $r->methods(), true));

        $this->assertNotNull($route, 'Expected DELETE api/v1/auth/account to be registered.');
        $this->assertContains('auth.customer', $route->middleware());
        $this->assertContains('throttle:auth-account-delete', $route->middleware());
    }

    // ================================================================
    // POST-DELETION AUTH
    // ================================================================

    public function test_old_access_token_fails_after_deletion(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $this->deleteAccount($customer['access_token'])->assertStatus(200);

        $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$customer['access_token']])
            ->assertStatus(401);
    }

    public function test_old_refresh_token_fails_after_deletion(): void
    {
        $customer = $this->createCartCustomer();
        $login = $this->issueCustomerSession($customer['user_uuid']);

        $accessToken = $login['access_token'];
        $refreshToken = $login['refresh_token'];

        $this->deleteAccount($accessToken)->assertStatus(200);

        // Repository convention (see docs/api-contracts/authentication-v1.md
        // "Refresh Access Token"): an invalid/revoked refresh token is a 422
        // business failure, not a 401 - 401 is reserved for a Bearer token
        // rejected by auth.customer/auth.admin middleware on a protected
        // route, which this public endpoint is not.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken])
            ->assertStatus(422);
    }

    public function test_login_via_otp_fails_after_deletion(): void
    {
        $customer = $this->createCartCustomer();
        $session = $this->loginCartCustomer($customer);

        $this->deleteAccount($session['access_token'])->assertStatus(200);

        // Repository convention (see docs/api-contracts/authentication-v1.md
        // §4a): request-otp is always 200/generic - non-enumerating - even
        // for a deleted account, so it must not disclose deletion via
        // response shape either.
        $this->postJson('/api/v1/auth/login/request-otp', [
            'phone_number' => $customer['phone_number'],
        ])->assertStatus(200);

        // No LOGIN OTP is ever actually issued for a deleted/ineligible
        // account (DeleteAccountAction tombstones the phone_number and
        // removes the CUSTOMER role), so there is no real code to guess -
        // verify-otp fails generically regardless of the code submitted.
        $this->postJson('/api/v1/auth/login/verify-otp', [
            'phone_number' => $customer['phone_number'],
            'otp_code' => '000000',
            'client_type' => 'MOBILE_IOS',
        ])->assertStatus(422);
    }

    public function test_forgot_password_cannot_resurrect_a_deleted_account(): void
    {
        $customer = $this->createCartCustomer();
        $session = $this->loginCartCustomer($customer);
        $this->deleteAccount($session['access_token'])->assertStatus(200);

        // Non-enumerating generic response either way - but no OTP is
        // created for the (now tombstoned) user, since no user row is
        // found by the original phone number any more.
        $this->postJson('/api/v1/auth/forgot-password', ['phone_number' => $customer['phone_number']])
            ->assertStatus(200);

        $otpCount = DB::table('otp_verifications')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->where('purpose_id', DB::table('otp_verification_purposes')->where('code', 'PASSWORD_RESET')->value('id'))
            ->count();

        $this->assertSame(0, $otpCount);
    }

    public function test_customer_role_no_longer_grants_access_after_deletion(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $this->deleteAccount($customer['access_token'])->assertStatus(200);

        $hasCustomerRole = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->where('roles.code', 'CUSTOMER')
            ->exists();

        $this->assertFalse($hasCustomerRole);
    }

    // ================================================================
    // PII
    // ================================================================

    public function test_old_phone_number_no_longer_remains_as_live_pii(): void
    {
        $customer = $this->createCartCustomer();
        $session = $this->loginCartCustomer($customer);
        $this->deleteAccount($session['access_token'])->assertStatus(200);

        $row = $this->userRow($customer['user_uuid']);
        $this->assertNotSame($customer['phone_number'], $row->phone_number);
        $this->assertStringStartsWith('DEL', $row->phone_number);
    }

    public function test_old_email_no_longer_remains_as_live_pii(): void
    {
        $customer = $this->createCartCustomer();
        $session = $this->loginCartCustomer($customer);
        $this->deleteAccount($session['access_token'])->assertStatus(200);

        $row = $this->userRow($customer['user_uuid']);
        $this->assertNotSame($customer['email'], $row->email);
        $this->assertStringContainsString('@deleted.invalid', $row->email);
    }

    public function test_full_name_is_anonymized_after_deletion(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $this->deleteAccount($customer['access_token'])->assertStatus(200);

        $fullName = DB::table('user_profiles')->where('user_id', UuidBinary::toBinary($customer['user_uuid']))->value('full_name');
        $this->assertSame('Deleted User', $fullName);
    }

    public function test_original_phone_and_email_can_be_reused_for_a_new_account(): void
    {
        $customer = $this->createCartCustomer();
        $session = $this->loginCartCustomer($customer);
        $this->deleteAccount($session['access_token'])->assertStatus(200);

        $dubaiCityId = (int) DB::table('cities')->where('code', 'DUBAI')->value('id');
        $dubaiAreaId = (int) DB::table('areas')->where('city_id', $dubaiCityId)->value('id');
        $propertyRelationshipTypeId = (int) DB::table('property_relationship_types')->value('id');
        $serviceCategoryId = (int) DB::table('service_categories')->value('id');

        $this->postJson('/api/v1/auth/register', [
            'full_name' => 'Reused Identity Customer',
            'phone_number' => $customer['phone_number'],
            'email' => $customer['email'],
            'password' => 'BrandNewPassw0rd',
            'city_id' => $dubaiCityId,
            'area_id' => $dubaiAreaId,
            'property_relationship_type_id' => $propertyRelationshipTypeId,
            'service_interests' => [$serviceCategoryId],
        ])->assertStatus(201);
    }

    // ================================================================
    // TRANSIENT DATA
    // ================================================================

    public function test_all_sessions_are_revoked_after_deletion(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $otherSession = $this->loginCartCustomer($this->createCartCustomer());

        $this->deleteAccount($customer['access_token'])->assertStatus(200);

        $revokedCount = DB::table('auth_sessions')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->whereNotNull('revoked_at')
            ->count();

        $totalCount = DB::table('auth_sessions')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->count();

        $this->assertGreaterThan(0, $totalCount);
        $this->assertSame($totalCount, $revokedCount);

        unset($otherSession);
    }

    public function test_pending_otps_are_invalidated_by_deletion(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->postJson('/api/v1/auth/change-phone-number', [
            'new_phone_number' => '+971509'.random_int(100000, 999999),
        ], ['Authorization' => 'Bearer '.$customer['access_token']])->assertStatus(200);

        $this->deleteAccount($customer['access_token'])->assertStatus(200);

        $pendingCount = DB::table('otp_verifications')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->where('status_id', DB::table('otp_verification_statuses')->where('code', 'PENDING')->value('id'))
            ->count();

        $this->assertSame(0, $pendingCount);
    }

    public function test_active_cart_is_deleted_when_it_has_no_financial_history(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $cartCountBefore = DB::table('carts')->where('customer_user_id', UuidBinary::toBinary($customer['user_uuid']))->count();
        $this->assertSame(1, $cartCountBefore);

        $this->deleteAccount($customer['access_token'])->assertStatus(200);

        $cartCountAfter = DB::table('carts')->where('customer_user_id', UuidBinary::toBinary($customer['user_uuid']))->count();
        $this->assertSame(0, $cartCountAfter);
    }

    public function test_appointment_hold_is_removed_with_its_untouched_cart(): void
    {
        $customer = $this->readyForPaymentCustomer();

        $holdCountBefore = DB::table('appointment_holds')
            ->join('carts', 'carts.id', '=', 'appointment_holds.cart_id')
            ->where('carts.customer_user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->count();
        $this->assertSame(1, $holdCountBefore);

        $this->deleteAccount($customer['access_token'])->assertStatus(200);

        $holdCountAfter = DB::table('appointment_holds')
            ->join('carts', 'carts.id', '=', 'appointment_holds.cart_id')
            ->where('carts.customer_user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->count();
        $this->assertSame(0, $holdCountAfter);
    }

    // ================================================================
    // PROPERTY
    // ================================================================

    public function test_property_never_used_by_a_contract_is_deleted(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);

        $this->deleteAccount($customer['access_token'])->assertStatus(200);

        $this->assertDatabaseMissing('customer_properties', ['id' => UuidBinary::toBinary($property['uuid'])]);
    }

    public function test_property_used_by_a_historical_contract_is_anonymized_not_deleted(): void
    {
        $fixture = $this->activeContractWithItem();
        $customer = $fixture['customer'];
        $contractUuid = $fixture['contract']->id;

        // Move the Contract to a terminal state so deletion becomes eligible.
        // BLUE V1 Phase A2.5 - grant a fresh step-up directly (see
        // AccountDeletionProcessorTest for the identical note).
        $this->markStepUpVerified($fixture['admin']['session_uuid']);
        $this->adminCancelContract($fixture['admin']['access_token'], UuidBinary::toString($contractUuid))->assertStatus(200);

        $this->deleteAccount($customer['access_token'])->assertStatus(200);

        $row = DB::table('customer_properties')->where('id', UuidBinary::toBinary($fixture['property_uuid']))->first();

        $this->assertNotNull($row, 'A Property referenced by a Contract must never be deleted.');
        $this->assertSame(0, (int) $row->is_active);
        $this->assertSame('Deleted property', $row->label);
        $this->assertSame('Deleted', $row->street_name);
        $this->assertSame('Deleted', $row->address_line);
        $this->assertNull($row->nearby_landmark);
        $this->assertNull($row->additional_location_notes);
    }

    // ================================================================
    // DUAL-ROLE (CUSTOMER + ADMIN) SAFETY
    // ================================================================

    public function test_a_customer_who_also_holds_an_active_admin_role_cannot_self_service_delete(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        DB::table('user_roles')->insert([
            'user_id' => UuidBinary::toBinary($customer['user_uuid']),
            'role_id' => (int) DB::table('roles')->where('code', 'ADMIN')->value('id'),
            'assigned_by_user_id' => null,
            'assigned_at' => now(),
        ]);

        $response = $this->deleteAccount($customer['access_token']);

        $response->assertStatus(409)->assertJson(['success' => false, 'data' => null]);

        $row = $this->userRow($customer['user_uuid']);
        $this->assertSame('ACTIVE', $this->accountStatusCode($row));
        $this->assertNull($row->deleted_at);

        $hasAdminRole = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->where('roles.code', 'ADMIN')
            ->exists();
        $this->assertTrue($hasAdminRole, 'The Admin identity must survive a blocked deletion attempt.');

        $this->assertSame(0, DB::table('customer_account_deletion_requests')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->count(), 'An Admin dual-role identity must never enter the deferred-deletion queue.');
    }

    public function test_a_customer_who_also_holds_an_active_super_admin_role_cannot_self_service_delete(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        DB::table('user_roles')->insert([
            'user_id' => UuidBinary::toBinary($customer['user_uuid']),
            'role_id' => (int) DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id'),
            'assigned_by_user_id' => null,
            'assigned_at' => now(),
        ]);

        $this->deleteAccount($customer['access_token'])->assertStatus(409);

        $this->assertSame(0, DB::table('customer_account_deletion_requests')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->count());
    }

    // ================================================================
    // ACTIVE OBLIGATIONS - deferred (PENDING, 202), never a hard refusal
    // ================================================================

    private function deletionRequestRow(string $userUuid): ?object
    {
        return DB::table('customer_account_deletion_requests')
            ->where('user_id', UuidBinary::toBinary($userUuid))
            ->first();
    }

    private function assertPendingResponse($response): void
    {
        $response->assertStatus(202)->assertJson([
            'success' => true,
            'message' => self::PENDING_MESSAGE,
        ]);
        $this->assertSame('PENDING', $response->json('data.deletion_status'));
        $this->assertNotNull($response->json('data.requested_at'));
    }

    public function test_non_terminal_booking_yields_pending_deletion_request(): void
    {
        ['customer' => $customer] = $this->successfulPayment();

        $response = $this->deleteAccount($customer['access_token']);

        $this->assertPendingResponse($response);

        $row = $this->userRow($customer['user_uuid']);
        $this->assertSame('ACTIVE', $this->accountStatusCode($row));
        $this->assertNull($row->deleted_at);

        $request = $this->deletionRequestRow($customer['user_uuid']);
        $this->assertNotNull($request);
        $this->assertNull($request->completed_at);
    }

    public function test_open_payment_attempt_yields_pending_deletion_request(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $this->createPayment($customer['access_token'], (string) Str::uuid())->assertStatus(201);

        $this->assertPendingResponse($this->deleteAccount($customer['access_token']));
    }

    public function test_requires_reconciliation_payment_yields_pending_deletion_request(): void
    {
        // An amount-mismatch webhook is the real, legitimate application
        // path that produces a payment_attempts row that is simultaneously
        // SUCCESSFUL, requires_reconciliation = 1, and has no Booking yet
        // (see App\Actions\Payment\ProcessPaymentWebhookAction) - no manual
        // DB manipulation needed to reach this exact blocking state.
        $customer = $this->readyForPaymentCustomer();
        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($response->json('data.payment.uuid'));

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => '1.000000',
        ]))->assertStatus(200);

        $fresh = $this->paymentRow(UuidBinary::toString($row->id));
        $this->assertSame(1, (int) $fresh->requires_reconciliation);

        $this->assertPendingResponse($this->deleteAccount($customer['access_token']));
    }

    public function test_successful_payment_not_yet_converted_to_booking_yields_pending_deletion_request(): void
    {
        // Simulates the narrow "payment succeeded but Booking conversion is
        // pending recovery" window (see App\Console\Commands\
        // ConvertSuccessfulPaymentsToBookings) by marking an otherwise
        // still-open payment_attempts row SUCCESSFUL directly, without ever
        // going through the webhook (which would synchronously create the
        // Booking via CreateBookingFromSuccessfulPaymentAction) - no
        // Booking is ever created, so there is nothing to cascade-delete.
        // This isolates hasOpenOrUnresolvedPayment()'s third condition
        // (SUCCESSFUL, no matching bookings row) from requires_reconciliation.
        $customer = $this->readyForPaymentCustomer();
        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($response->json('data.payment.uuid'));

        // Explicit microsecond formatting - a bare Carbon instance's
        // implicit string cast truncates to whole seconds, which can then
        // sort earlier than payment_attempts.created_at's own microsecond
        // precision and trip chk_payment_attempts_finalized_at.
        $timestamp = now()->format('Y-m-d H:i:s.u');

        DB::table('payment_attempts')->where('id', $row->id)->update([
            'status_id' => DB::table('payment_statuses')->where('code', 'SUCCESSFUL')->value('id'),
            'requires_reconciliation' => 0,
            'confirmed_amount' => $row->requested_amount,
            'successful_at' => $timestamp,
            'finalized_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $this->assertSame(0, DB::table('bookings')->where('payment_attempt_id', $row->id)->count());

        $this->assertPendingResponse($this->deleteAccount($customer['access_token']));
    }

    public function test_requested_contract_yields_pending_deletion_request(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $service = $this->createSubscriptionEligibleService();

        $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(201);

        $this->assertPendingResponse($this->deleteAccount($customer['access_token']));
    }

    public function test_active_contract_with_stripe_billing_yields_pending_deletion_request(): void
    {
        $fixture = $this->activeContractWithItem();

        $this->assertPendingResponse($this->deleteAccount($fixture['customer']['access_token']));

        $this->assertSame('ACTIVE', DB::table('service_contract_statuses')
            ->where('id', $fixture['contract']->status_id)
            ->value('code'));
    }

    public function test_exactly_one_deletion_request_is_persisted_per_customer(): void
    {
        ['customer' => $customer] = $this->successfulPayment();

        $this->deleteAccount($customer['access_token'])->assertStatus(202);

        $count = DB::table('customer_account_deletion_requests')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_repeated_delete_while_pending_is_idempotent_and_requested_at_stable(): void
    {
        ['customer' => $customer] = $this->successfulPayment();

        $first = $this->deleteAccount($customer['access_token']);
        $this->assertPendingResponse($first);
        $firstRequestedAt = $first->json('data.requested_at');

        $second = $this->deleteAccount($customer['access_token']);
        $this->assertPendingResponse($second);

        $this->assertSame($firstRequestedAt, $second->json('data.requested_at'));

        $count = DB::table('customer_account_deletion_requests')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_retry_after_obligation_resolves_completes_deletion(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $this->assertPendingResponse($this->deleteAccount($customer['access_token']));

        $this->postJson('/api/v1/bookings/'.UuidBinary::toString($booking->id).'/cancel', [], [
            'Authorization' => 'Bearer '.$customer['access_token'],
        ])->assertStatus(200);

        $this->deleteAccount($customer['access_token'])->assertStatus(200)->assertJson([
            'success' => true,
            'message' => 'Account deleted successfully.',
            'data' => null,
        ]);

        $row = $this->userRow($customer['user_uuid']);
        $this->assertSame('DEACTIVATED', $this->accountStatusCode($row));
        $this->assertNotNull($row->deleted_at);

        $request = $this->deletionRequestRow($customer['user_uuid']);
        $this->assertNotNull($request->completed_at);
    }

    // ================================================================
    // HISTORICAL RETENTION
    // ================================================================

    public function test_cancelled_historical_booking_survives_deletion_structurally_intact(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $this->postJson('/api/v1/bookings/'.UuidBinary::toString($booking->id).'/cancel', [], [
            'Authorization' => 'Bearer '.$customer['access_token'],
        ])->assertStatus(200);

        $this->deleteAccount($customer['access_token'])->assertStatus(200);

        $after = $this->bookingRow(UuidBinary::toString($booking->id));
        $this->assertNotNull($after);
        $this->assertSame($booking->booking_number, $after->booking_number);
        $this->assertSame('CANCELLED', DB::table('booking_statuses')->where('id', $after->status_id)->value('code'));
        $this->assertNotNull($after->cancelled_at);
    }

    public function test_successful_payment_financial_record_survives_deletion_structurally_intact(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $this->postJson('/api/v1/bookings/'.UuidBinary::toString($booking->id).'/cancel', [], [
            'Authorization' => 'Bearer '.$customer['access_token'],
        ])->assertStatus(200);

        $this->deleteAccount($customer['access_token'])->assertStatus(200);

        $after = DB::table('payment_attempts')->where('id', $payment->id)->first();
        $this->assertNotNull($after);
        $this->assertSame($payment->requested_amount, $after->requested_amount);
        $this->assertSame($payment->confirmed_amount, $after->confirmed_amount);
        $this->assertSame('SUCCESSFUL', DB::table('payment_statuses')->where('id', $after->status_id)->value('code'));
    }

    public function test_immutable_checkout_snapshot_is_not_corrupted_by_deletion(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $this->postJson('/api/v1/bookings/'.UuidBinary::toString($booking->id).'/cancel', [], [
            'Authorization' => 'Bearer '.$customer['access_token'],
        ])->assertStatus(200);

        $this->deleteAccount($customer['access_token'])->assertStatus(200);

        $after = DB::table('payment_attempts')->where('id', $payment->id)->first();
        $this->assertSame($payment->checkout_snapshot, $after->checkout_snapshot);
        $this->assertSame($payment->checkout_snapshot_hash, $after->checkout_snapshot_hash);
    }

    public function test_cancelled_contract_history_survives_deletion_structurally_intact(): void
    {
        $fixture = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($fixture['contract']->id);

        // BLUE V1 Phase A2.5 - grant a fresh step-up directly (see
        // AccountDeletionProcessorTest for the identical note).
        $this->markStepUpVerified($fixture['admin']['session_uuid']);
        $this->adminCancelContract($fixture['admin']['access_token'], $contractUuid)->assertStatus(200);

        $billingBefore = $this->billingRow($contractUuid);

        $this->deleteAccount($fixture['customer']['access_token'])->assertStatus(200);

        $contractAfter = $this->contractRow($contractUuid);
        $this->assertNotNull($contractAfter);
        $this->assertSame('CANCELLED', DB::table('service_contract_statuses')->where('id', $contractAfter->status_id)->value('code'));
        $this->assertSame($fixture['contract']->contract_number, $contractAfter->contract_number);

        $billingAfter = $this->billingRow($contractUuid);
        $this->assertNotNull($billingAfter, 'Contract billing/reconciliation record must survive.');
        $this->assertSame($billingBefore->stripe_subscription_id, $billingAfter->stripe_subscription_id);
        $this->assertSame($billingBefore->stripe_customer_id, $billingAfter->stripe_customer_id);
    }

    // ================================================================
    // ATOMICITY
    // ================================================================

    public function test_a_mid_deletion_failure_rolls_back_every_write(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $originalFullName = DB::table('user_profiles')->where('user_id', UuidBinary::toBinary($customer['user_uuid']))->value('full_name');
        $originalUser = $this->userRow($customer['user_uuid']);

        // Forces DeleteAccountAction's final lookupId('user_account_statuses', 'DEACTIVATED')
        // call to fail, AFTER every other write in the same transaction has
        // already been attempted - proving the whole transaction rolls back
        // together rather than leaving a half-deleted customer.
        DB::table('user_account_statuses')->where('code', 'DEACTIVATED')->update(['code' => 'DEACTIVATED_TEMP_RENAMED']);

        $threw = false;

        try {
            app(DeleteAccountAction::class)->handle($customer['user_uuid'], ['current_password' => self::FIXTURE_PASSWORD]);
        } catch (RuntimeException) {
            $threw = true;
        } finally {
            DB::table('user_account_statuses')->where('code', 'DEACTIVATED_TEMP_RENAMED')->update(['code' => 'DEACTIVATED']);
        }

        $this->assertTrue($threw, 'Expected the missing DEACTIVATED reference row to throw.');

        $afterUser = $this->userRow($customer['user_uuid']);
        $this->assertSame($originalUser->phone_number, $afterUser->phone_number);
        $this->assertSame($originalUser->email, $afterUser->email);
        $this->assertSame($originalUser->account_status_id, $afterUser->account_status_id);
        $this->assertNull($afterUser->deleted_at);

        $afterFullName = DB::table('user_profiles')->where('user_id', UuidBinary::toBinary($customer['user_uuid']))->value('full_name');
        $this->assertSame($originalFullName, $afterFullName);
    }

    // ================================================================
    // RESPONSE SAFETY
    // ================================================================

    public function test_successful_response_exposes_no_internal_fields(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $response = $this->deleteAccount($customer['access_token']);

        $response->assertStatus(200);
        $this->assertNull($response->json('data'));

        $raw = $response->getContent();
        $this->assertTrue(mb_check_encoding($raw, 'UTF-8'));
        json_decode($raw, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());

        foreach (['password', 'hash', 'stripe', 'uuid', 'id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($raw));
        }
    }

    public function test_pending_response_exposes_no_internal_fields(): void
    {
        ['customer' => $customer] = $this->successfulPayment();

        $response = $this->deleteAccount($customer['access_token']);

        $response->assertStatus(202);
        $this->assertEqualsCanonicalizing(['deletion_status', 'requested_at'], array_keys($response->json('data')));

        $raw = strtolower($response->getContent());
        $this->assertTrue(mb_check_encoding($response->getContent(), 'UTF-8'));
        json_decode($response->getContent(), true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());

        foreach (['stripe', 'booking_id', 'contract_id', 'payment_attempt', 'sql', 'exception', 'binary', '\\x'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw);
        }
    }

    public function test_admin_rejection_response_exposes_no_internal_fields(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        DB::table('user_roles')->insert([
            'user_id' => UuidBinary::toBinary($customer['user_uuid']),
            'role_id' => (int) DB::table('roles')->where('code', 'ADMIN')->value('id'),
            'assigned_by_user_id' => null,
            'assigned_at' => now(),
        ]);

        $response = $this->deleteAccount($customer['access_token']);

        $response->assertStatus(409);
        $this->assertNull($response->json('data'));
    }
}
