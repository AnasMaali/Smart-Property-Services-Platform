<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B6 - read-only global Admin Customer/Property visibility
 * (App\Actions\Admin\Customer\AdminListCustomersAction/AdminGetCustomerAction,
 * App\Actions\Admin\Property\AdminGetPropertyAction, App\Support\Admin\
 * AdminCustomerPresenter). Mirrors AdminBookingReadTest/AdminPaymentReadTest's
 * structure/conventions exactly. No mutation endpoint exists for this
 * module - every test here is read-only.
 */
class AdminCustomerReadTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    // -----------------------------------------------------------------
    // CUSTOMERS
    // -----------------------------------------------------------------

    public function test_admin_can_list_customers(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();

        $response = $this->getJson('/api/v1/admin/customers', $this->bearer($admin['access_token']));

        $response->assertStatus(200)->assertJson(['success' => true]);
        $uuids = collect($response->json('data.customers'))->pluck('uuid')->all();
        $this->assertContains($customer['user_uuid'], $uuids);
    }

    public function test_super_admin_can_list_customers(): void
    {
        $admin = $this->createAndLoginAdmin(['SUPER_ADMIN']);
        $this->createAuthenticatedCartCustomer();

        $this->getJson('/api/v1/admin/customers', $this->bearer($admin['access_token']))
            ->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_customer_cannot_list_admin_customers(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->getJson('/api/v1/admin/customers', $this->bearer($customer['access_token']))
            ->assertStatus(401);
    }

    public function test_unauthenticated_request_cannot_list_admin_customers(): void
    {
        $this->getJson('/api/v1/admin/customers')->assertStatus(401);
    }

    public function test_pagination_shape_is_present(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->createAuthenticatedCartCustomer();
        $this->createAuthenticatedCartCustomer();

        $response = $this->getJson('/api/v1/admin/customers?per_page=1&page=1', $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $this->assertSame(1, count($response->json('data.customers')));
        $this->assertSame(1, $response->json('data.pagination.per_page'));
        $this->assertGreaterThanOrEqual(2, $response->json('data.pagination.total'));
    }

    public function test_account_status_filter_only_returns_matching_customers(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $active = $this->createAuthenticatedCartCustomer();

        $matching = $this->getJson('/api/v1/admin/customers?account_status=ACTIVE', $this->bearer($admin['access_token']))
            ->assertStatus(200);
        $this->assertContains($active['user_uuid'], collect($matching->json('data.customers'))->pluck('uuid')->all());

        $nonMatching = $this->getJson('/api/v1/admin/customers?account_status=SUSPENDED', $this->bearer($admin['access_token']))
            ->assertStatus(200);
        $this->assertNotContains($active['user_uuid'], collect($nonMatching->json('data.customers'))->pluck('uuid')->all());
    }

    public function test_search_filter_matches_full_name(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createCartCustomer();
        $fullName = DB::table('user_profiles')->where('user_id', UuidBinary::toBinary($customer['user_uuid']))->value('full_name');

        $response = $this->getJson(
            '/api/v1/admin/customers?search='.urlencode(substr($fullName, 0, 8)),
            $this->bearer($admin['access_token']),
        );

        $this->assertContains($customer['user_uuid'], collect($response->json('data.customers'))->pluck('uuid')->all());
    }

    public function test_phone_number_filter_returns_exactly_that_customer(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createCartCustomer();

        $response = $this->getJson(
            '/api/v1/admin/customers?phone_number='.urlencode($customer['phone_number']),
            $this->bearer($admin['access_token']),
        );

        $uuids = collect($response->json('data.customers'))->pluck('uuid')->all();
        $this->assertSame([$customer['user_uuid']], $uuids);
    }

    public function test_admin_can_view_customer_detail_for_any_customer(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $this->createProperty($customer['access_token']);

        $response = $this->getJson(
            '/api/v1/admin/customers/'.$customer['user_uuid'],
            $this->bearer($admin['access_token']),
        );

        $response->assertStatus(200)->assertJson(['success' => true]);
        $data = $response->json('data.customer');
        $this->assertSame($customer['user_uuid'], $data['uuid']);
        $this->assertSame('ACTIVE', $data['account_status']);
        $this->assertSame('NONE', $data['account_deletion']['status']);
        $this->assertCount(1, $data['properties']);
        $this->assertSame(0, $data['activity']['bookings_count']);
    }

    public function test_malformed_customer_uuid_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/customers/not-a-uuid', $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    public function test_unknown_customer_uuid_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/customers/'.UuidBinary::generate(), $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    public function test_pure_admin_account_without_customer_profile_is_not_listed_as_a_customer(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->getJson('/api/v1/admin/customers/'.$admin['user_uuid'], $this->bearer($admin['access_token']));

        $response->assertStatus(404);
    }

    public function test_customer_cannot_view_admin_customer_detail(): void
    {
        $customerA = $this->createAuthenticatedCartCustomer();
        $customerB = $this->createAuthenticatedCartCustomer();

        $this->getJson(
            '/api/v1/admin/customers/'.$customerB['user_uuid'],
            $this->bearer($customerA['access_token']),
        )->assertStatus(401);
    }

    public function test_customer_detail_never_exposes_security_material(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();

        $response = $this->getJson(
            '/api/v1/admin/customers/'.$customer['user_uuid'],
            $this->bearer($admin['access_token']),
        );

        $json = json_encode($response->json());

        foreach ([
            'password_hash',
            'refresh_token_hash',
            'client_secret',
            'stripe_customer_id',
        ] as $forbiddenKey) {
            $this->assertStringNotContainsString($forbiddenKey, $json, "Response must never contain {$forbiddenKey}.");
        }
    }

    public function test_pending_account_deletion_state_is_reflected(): void
    {
        // A fresh customer with no non-terminal Booking/Contract is deleted
        // immediately (200) - a real PENDING deletion request requires a
        // customer with a still-active Contract, exactly like
        // DeleteAccountTest::test_processor_completes_deletion_once_contract_becomes_terminal.
        $ctx = $this->activeContractWithItem();

        $this->deleteJson(
            '/api/v1/auth/account',
            ['current_password' => 'CartTestPassw0rd'],
            $this->bearer($ctx['customer']['access_token']),
        )->assertStatus(202);

        $response = $this->getJson(
            '/api/v1/admin/customers/'.$ctx['customer']['user_uuid'],
            $this->bearer($ctx['admin']['access_token']),
        );

        $data = $response->json('data.customer');
        $this->assertSame('PENDING', $data['account_deletion']['status']);
        $this->assertNotNull($data['account_deletion']['requested_at']);
    }

    // -----------------------------------------------------------------
    // PROPERTIES
    // -----------------------------------------------------------------

    public function test_admin_can_inspect_a_customers_property(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);

        $response = $this->getJson(
            '/api/v1/admin/properties/'.$property['uuid'],
            $this->bearer($admin['access_token']),
        );

        $response->assertStatus(200)->assertJson(['success' => true]);
        $data = $response->json('data');
        $this->assertSame($property['uuid'], $data['property']['uuid']);
        $this->assertSame($customer['user_uuid'], $data['customer']['uuid']);
        $this->assertIsArray($data['contracts']);
    }

    public function test_customer_cannot_view_admin_property_detail(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);

        $this->getJson(
            '/api/v1/admin/properties/'.$property['uuid'],
            $this->bearer($customer['access_token']),
        )->assertStatus(401);
    }

    public function test_malformed_property_uuid_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/properties/not-a-uuid', $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    public function test_unknown_property_uuid_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/properties/'.UuidBinary::generate(), $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    public function test_admin_property_read_does_not_mutate_the_property(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $before = $this->propertyRow($property['uuid']);

        $this->getJson('/api/v1/admin/properties/'.$property['uuid'], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $after = $this->propertyRow($property['uuid']);
        $this->assertEquals($before->updated_at, $after->updated_at);
    }

    public function test_property_detail_never_exposes_raw_binary_ids(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);

        $response = $this->getJson('/api/v1/admin/properties/'.$property['uuid'], $this->bearer($admin['access_token']));

        $this->assertSame($property['uuid'], $response->json('data.property.uuid'));
        $this->assertSame($customer['user_uuid'], $response->json('data.customer.uuid'));
    }

    // -----------------------------------------------------------------
    // CROSS-DOMAIN REGRESSIONS
    // -----------------------------------------------------------------

    public function test_existing_customer_property_ownership_enforcement_is_unchanged(): void
    {
        $customerA = $this->createAuthenticatedCartCustomer();
        $customerB = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customerA['access_token']);

        $this->getJson(
            '/api/v1/properties/'.$property['uuid'],
            ['Authorization' => 'Bearer '.$customerB['access_token']],
        )->assertStatus(404);
    }

    public function test_existing_customer_profile_endpoint_is_unaffected(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$customer['access_token']])
            ->assertStatus(200);
    }
}
