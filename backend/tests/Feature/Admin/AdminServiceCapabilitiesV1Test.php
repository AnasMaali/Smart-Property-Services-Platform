<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Admin Service Capabilities Management. Covers: Admin
 * set-capabilities CRUD/validation/authorization/idempotency/audit, the
 * read-only `service_capability_types` lookup, and - most importantly -
 * the CART_ELIGIBLE/SUBSCRIPTION runtime-behavior regression suite proving
 * a capability change is FORWARD-LOOKING ONLY: it never deletes an
 * existing CartItem, never rewrites a Booking/Payment snapshot, and never
 * cancels or rewrites an existing Contract. See
 * App\Actions\Admin\Service\AdminSetServiceCapabilitiesAction's docblock
 * for the full safety story this suite proves.
 */
class AdminServiceCapabilitiesV1Test extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    private function setCapabilities(string $accessToken, string $serviceUuid, array $codes): TestResponse
    {
        return $this->postJson(
            "/api/v1/admin/services/{$serviceUuid}/capabilities",
            ['capabilities' => $codes],
            $this->bearer($accessToken),
        );
    }

    private function capabilityCodesOf(TestResponse $response): array
    {
        return collect($response->json('data.service.capabilities'))->pluck('code')->sort()->values()->all();
    }

    // -----------------------------------------------------------------
    // Admin mutation CRUD / validation / authorization / idempotency
    // -----------------------------------------------------------------

    public function test_admin_can_set_capabilities(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService(overrides: ['cart_eligible' => false]);

        $response = $this->setCapabilities($admin['access_token'], $service['uuid'], ['CART_ELIGIBLE', 'SUBSCRIPTION']);

        $response->assertStatus(200);
        $this->assertSame(['CART_ELIGIBLE', 'SUBSCRIPTION'], $this->capabilityCodesOf($response));

        $reread = $this->getJson("/api/v1/admin/services/{$service['uuid']}", $this->bearer($admin['access_token']));
        $this->assertSame(['CART_ELIGIBLE', 'SUBSCRIPTION'], $this->capabilityCodesOf($reread));
    }

    public function test_admin_can_remove_a_capability(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();
        $this->setCapabilities($admin['access_token'], $service['uuid'], ['CART_ELIGIBLE', 'SUBSCRIPTION'])->assertStatus(200);

        $response = $this->setCapabilities($admin['access_token'], $service['uuid'], ['CART_ELIGIBLE']);

        $response->assertStatus(200);
        $this->assertSame(['CART_ELIGIBLE'], $this->capabilityCodesOf($response));
    }

    public function test_admin_can_clear_all_capabilities(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();

        $response = $this->setCapabilities($admin['access_token'], $service['uuid'], []);

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.service.capabilities'));
    }

    public function test_duplicate_input_codes_are_deduplicated_safely(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService(overrides: ['cart_eligible' => false]);

        $response = $this->setCapabilities($admin['access_token'], $service['uuid'], ['CART_ELIGIBLE', 'CART_ELIGIBLE', 'CART_ELIGIBLE']);

        $response->assertStatus(200);
        $this->assertSame(['CART_ELIGIBLE'], $this->capabilityCodesOf($response));
    }

    public function test_unknown_capability_code_returns_422(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();

        $response = $this->setCapabilities($admin['access_token'], $service['uuid'], ['NOT_A_REAL_CAPABILITY']);

        $response->assertStatus(422);

        // Rejection must not have written a partial state change.
        $reread = $this->getJson("/api/v1/admin/services/{$service['uuid']}", $this->bearer($admin['access_token']));
        $this->assertSame(['CART_ELIGIBLE'], $this->capabilityCodesOf($reread));
    }

    public function test_inactive_capability_type_is_rejected(): void
    {
        DB::table('service_capability_types')->where('code', 'EMERGENCY')->update(['is_active' => 0]);

        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();

        $response = $this->setCapabilities($admin['access_token'], $service['uuid'], ['CART_ELIGIBLE', 'EMERGENCY']);

        $response->assertStatus(422);
    }

    public function test_unknown_service_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->setCapabilities($admin['access_token'], (string) Str::uuid(), ['CART_ELIGIBLE']);

        $response->assertStatus(404);
    }

    public function test_unauthenticated_request_cannot_set_capabilities(): void
    {
        $service = $this->createCartService();

        $this->postJson("/api/v1/admin/services/{$service['uuid']}/capabilities", ['capabilities' => ['CART_ELIGIBLE']])
            ->assertStatus(401);
    }

    public function test_customer_cannot_set_capabilities(): void
    {
        $service = $this->createCartService();
        $customer = $this->createAuthenticatedCartCustomer();

        $this->postJson(
            "/api/v1/admin/services/{$service['uuid']}/capabilities",
            ['capabilities' => ['CART_ELIGIBLE']],
            ['Authorization' => 'Bearer '.$customer['access_token']],
        )->assertStatus(401);
    }

    public function test_admin_without_services_manage_cannot_set_capabilities(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();

        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'services.manage')->value('id');

        DB::table('admin_role_permissions')
            ->where('role_id', $adminRoleId)
            ->where('permission_id', $permissionId)
            ->delete();

        $response = $this->setCapabilities($admin['access_token'], $service['uuid'], ['CART_ELIGIBLE']);

        $response->assertStatus(403)->assertExactJson([
            'success' => false,
            'message' => 'You are not authorized to perform this action.',
        ]);
    }

    public function test_identical_capability_set_is_idempotent_and_writes_no_audit_row(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();

        $before = $this->auditLogsFor($service['uuid'])->count();

        // Same set as the fixture's default (CART_ELIGIBLE only) - a no-op.
        $response = $this->setCapabilities($admin['access_token'], $service['uuid'], ['CART_ELIGIBLE']);

        $response->assertStatus(200);
        $this->assertSame($before, $this->auditLogsFor($service['uuid'])->count());
    }

    public function test_audit_logs_before_and_after_values(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();

        $this->setCapabilities($admin['access_token'], $service['uuid'], ['CART_ELIGIBLE', 'SUBSCRIPTION'])->assertStatus(200);

        $audit = $this->auditLogsFor($service['uuid'])->last();

        $this->assertSame('SERVICE_CAPABILITIES_CHANGED', $audit->action_code);
        $this->assertSame(['capabilities' => ['CART_ELIGIBLE']], json_decode($audit->old_values, true));
        $this->assertSame(['capabilities' => ['CART_ELIGIBLE', 'SUBSCRIPTION']], json_decode($audit->new_values, true));
    }

    public function test_api_response_never_exposes_raw_ids(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();

        $response = $this->setCapabilities($admin['access_token'], $service['uuid'], ['CART_ELIGIBLE', 'QUOTE_ONLY']);

        $response->assertStatus(200);

        foreach ($response->json('data.service.capabilities') as $capability) {
            $this->assertSame(['code', 'name', 'description'], array_keys($capability));
        }
    }

    public function test_service_capability_types_lookup_is_read_only_and_lists_seeded_codes(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->getJson('/api/v1/admin/service-capability-types', $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $codes = collect($response->json('data.service_capability_types'))->pluck('code')->sort()->values()->all();

        $this->assertSame(
            ['CART_ELIGIBLE', 'EMERGENCY', 'QUOTE_ONLY', 'REQUIRES_SITE_VISIT', 'SUBSCRIPTION'],
            $codes,
        );
    }

    // -----------------------------------------------------------------
    // CART_ELIGIBLE regression - forward-looking only
    // -----------------------------------------------------------------

    public function test_adding_cart_eligible_enables_future_add_to_cart(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService(overrides: ['cart_eligible' => false]);
        $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($this->schemeForLatestVersion($service['uuid']));

        $customer = $this->createAuthenticatedCartCustomer();

        // Not yet eligible.
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(422);

        $this->setCapabilities($admin['access_token'], $service['uuid'], ['CART_ELIGIBLE'])->assertStatus(200);

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
    }

    public function test_removing_cart_eligible_blocks_future_add_and_update_but_leaves_existing_item(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();
        $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($this->schemeForLatestVersion($service['uuid']));

        $customer = $this->createAuthenticatedCartCustomer();

        $addResponse = $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid'], 'quantity' => 1])->assertStatus(201);
        $itemUuid = collect($addResponse->json('data.cart.items'))->pluck('uuid')->first();

        $this->setCapabilities($admin['access_token'], $service['uuid'], [])->assertStatus(200);

        // Existing CartItem is left untouched - no cascade delete.
        $cart = $this->getCart($customer['access_token'])->assertStatus(200);
        $itemUuids = collect($cart->json('data.cart.items'))->pluck('uuid')->all();
        $this->assertContains($itemUuid, $itemUuids);

        // Future add of the same (now-ineligible) service is blocked.
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(422);

        // Future update of the already-placed item is also blocked (the
        // documented, pre-existing UpdateCartItemAction recheck).
        $this->updateCartItem($customer['access_token'], $itemUuid, ['quantity' => 2])->assertStatus(422);
    }

    public function test_removing_cart_eligible_does_not_alter_an_existing_bookings_snapshot(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $paid = $this->successfulPayment();
        $bookingBefore = $this->bookingRowForPayment($paid['payment']);
        $this->assertNotNull($bookingBefore);

        $serviceIdBinary = DB::table('booking_items')->where('booking_id', $bookingBefore->id)->value('service_id');
        $serviceUuid = UuidBinary::toString($serviceIdBinary);

        $bookingsCountBefore = DB::table('bookings')->count();
        $paymentsCountBefore = DB::table('payment_attempts')->count();

        $this->setCapabilities($admin['access_token'], $serviceUuid, [])->assertStatus(200);

        $this->assertSame($bookingsCountBefore, DB::table('bookings')->count());
        $this->assertSame($paymentsCountBefore, DB::table('payment_attempts')->count());

        $bookingAfter = $this->bookingRowForPayment($paid['payment']);
        $this->assertEquals($bookingBefore, $bookingAfter);
    }

    // -----------------------------------------------------------------
    // SUBSCRIPTION regression - forward-looking only
    // -----------------------------------------------------------------

    public function test_adding_subscription_enables_future_contract_request(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService(overrides: ['cart_eligible' => false]);

        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);

        $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(422);

        $this->setCapabilities($admin['access_token'], $service['uuid'], ['SUBSCRIPTION'])->assertStatus(200);

        $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(201);
    }

    public function test_removing_subscription_blocks_future_contract_request(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createSubscriptionEligibleService();

        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);

        $this->setCapabilities($admin['access_token'], $service['uuid'], [])->assertStatus(200);

        $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(422);
    }

    public function test_removing_subscription_blocks_pending_contract_approval(): void
    {
        $service = $this->createSubscriptionEligibleService();

        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);

        $requestResponse = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(201);

        $contractUuid = $requestResponse->json('data.contract.uuid');

        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->setCapabilities($admin['access_token'], $service['uuid'], [])->assertStatus(200);

        $this->adminApproveContract($admin['access_token'], $contractUuid, $this->approveContractPayload($service['uuid']))
            ->assertStatus(422);

        $contract = $this->contractRow($contractUuid);
        $this->assertSame(0, $this->contractItemRows($contractUuid)->count());
        $this->assertNotNull($contract);
    }

    public function test_removing_subscription_leaves_an_active_contract_and_its_entitlement_intact(): void
    {
        $fixture = $this->activeContractWithItem();

        $contractUuid = UuidBinary::toString($fixture['contract']->id);
        $itemUuid = UuidBinary::toString($fixture['item']->id);
        $itemsBefore = $this->contractItemRows($contractUuid);

        $this->setCapabilities($fixture['admin']['access_token'], $fixture['service']['uuid'], [])->assertStatus(200);

        // The Contract and its item are completely unaffected.
        $contractAfter = $this->getContractHttp($fixture['customer']['access_token'], $contractUuid)->assertStatus(200);
        $this->assertSame('ACTIVE', $contractAfter->json('data.contract.status'));

        $itemsAfter = $this->contractItemRows($contractUuid);
        $this->assertSame($itemsBefore->count(), $itemsAfter->count());
        $this->assertEquals($fixture['item'], $itemsAfter->first());

        // Ongoing entitlement usage (booking a covered visit) is likewise
        // unaffected - CreateContractBookingAction never checks capability.
        $slot = $this->createAppointmentSlot();
        $this->bookContractService($fixture['customer']['access_token'], $contractUuid, $itemUuid, $slot['uuid'])
            ->assertStatus(201);
    }

    // -----------------------------------------------------------------
    // Inert capabilities - vocabulary-only, no invented behavior
    // -----------------------------------------------------------------

    public function test_inert_capabilities_can_be_stored_and_read(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService(overrides: ['cart_eligible' => false]);

        $response = $this->setCapabilities($admin['access_token'], $service['uuid'], ['QUOTE_ONLY', 'EMERGENCY', 'REQUIRES_SITE_VISIT']);

        $response->assertStatus(200);
        $this->assertSame(['EMERGENCY', 'QUOTE_ONLY', 'REQUIRES_SITE_VISIT'], $this->capabilityCodesOf($response));

        $reread = $this->getJson("/api/v1/admin/services/{$service['uuid']}", $this->bearer($admin['access_token']));
        $this->assertSame(['EMERGENCY', 'QUOTE_ONLY', 'REQUIRES_SITE_VISIT'], $this->capabilityCodesOf($reread));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function schemeForLatestVersion(string $serviceUuid): string
    {
        $serviceIdBinary = UuidBinary::toBinary($serviceUuid);

        $versionId = DB::table('pricing_scheme_versions')
            ->where('service_id', $serviceIdBinary)
            ->orderByDesc('created_at')
            ->value('id');

        return UuidBinary::toString($versionId);
    }
}
