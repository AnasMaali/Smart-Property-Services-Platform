<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\Support\DeletesCustomerAccountsForTests;
use Tests\TestCase;

/**
 * GET /v1/auth/account-deletion (App\Actions\Auth\GetAccountDeletionStatusAction)
 * - lets the Flutter client recover PENDING deletion state after a
 * restart. Always reads only the authenticated caller's own state; there
 * is no user-id input for it to ever read someone else's.
 */
class AccountDeletionStatusTest extends TestCase
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

    private function deletionStatus(string $accessToken)
    {
        return $this->getJson('/api/v1/auth/account-deletion', ['Authorization' => 'Bearer '.$accessToken]);
    }

    public function test_unauthenticated_caller_cannot_read_deletion_status(): void
    {
        $this->getJson('/api/v1/auth/account-deletion')->assertStatus(401);
    }

    public function test_route_is_protected_by_auth_customer(): void
    {
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v1/auth/account-deletion' && in_array('GET', $r->methods(), true));

        $this->assertNotNull($route, 'Expected GET api/v1/auth/account-deletion to be registered.');
        $this->assertContains('auth.customer', $route->middleware());
    }

    public function test_status_is_none_when_no_deletion_was_ever_requested(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $response = $this->deletionStatus($customer['access_token']);

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['deletion_status' => 'NONE', 'requested_at' => null],
        ]);
    }

    public function test_status_is_pending_with_requested_at_after_a_blocked_deletion_request(): void
    {
        ['customer' => $customer] = $this->successfulPayment();

        $deleteResponse = $this->deleteAccount($customer['access_token']);
        $deleteResponse->assertStatus(202);
        $requestedAt = $deleteResponse->json('data.requested_at');

        $response = $this->deletionStatus($customer['access_token']);

        $response->assertStatus(200);
        $this->assertSame('PENDING', $response->json('data.deletion_status'));
        $this->assertSame($requestedAt, $response->json('data.requested_at'));
    }

    public function test_status_response_exposes_no_database_or_request_identifiers(): void
    {
        ['customer' => $customer] = $this->successfulPayment();
        $this->deleteAccount($customer['access_token'])->assertStatus(202);

        $response = $this->deletionStatus($customer['access_token']);

        $this->assertEqualsCanonicalizing(['deletion_status', 'requested_at'], array_keys($response->json('data')));

        $raw = $response->getContent();
        $this->assertTrue(mb_check_encoding($raw, 'UTF-8'));
        json_decode($raw, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    public function test_a_customer_can_never_read_another_customers_deletion_status(): void
    {
        ['customer' => $customerA] = $this->successfulPayment();
        $this->deleteAccount($customerA['access_token'])->assertStatus(202);

        $customerB = $this->createAuthenticatedCartCustomer();

        // The architecture makes cross-customer reads impossible by
        // construction: there is no user-id route/query parameter at all,
        // only the authenticated caller's own identity - this proves
        // customer B's own call always reflects only customer B's state,
        // never customer A's PENDING request.
        $response = $this->deletionStatus($customerB['access_token']);

        $response->assertStatus(200)->assertJson([
            'data' => ['deletion_status' => 'NONE', 'requested_at' => null],
        ]);
    }

    public function test_deletion_status_no_rate_limiter_beyond_auth_customer_group(): void
    {
        // Read-only, low-risk, authenticated - matches every other GET
        // endpoint under auth.customer (e.g. GET /v1/profile), none of
        // which carry a dedicated throttle beyond the group's own
        // middleware.
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v1/auth/account-deletion' && in_array('GET', $r->methods(), true));

        $this->assertNotNull($route);
        $throttles = array_filter($route->middleware(), fn ($m) => str_starts_with($m, 'throttle:'));
        $this->assertSame([], array_values($throttles));
    }
}
