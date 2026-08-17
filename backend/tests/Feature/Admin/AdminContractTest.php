<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

class AdminContractTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_customer_token_cannot_access_admin_contract_list(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $response = $this->getJson('/api/v1/admin/contracts', ['Authorization' => 'Bearer '.$customer['access_token']]);

        $response->assertStatus(401);
    }

    public function test_customer_token_cannot_approve_a_contract(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $service = $this->createSubscriptionEligibleService();

        $created = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(201);

        $response = $this->postJson(
            '/api/v1/admin/contracts/'.$created->json('data.contract.uuid').'/approve',
            $this->approveContractPayload($service['uuid']),
            ['Authorization' => 'Bearer '.$customer['access_token']]
        );

        $response->assertStatus(401);
    }

    public function test_missing_token_cannot_access_admin_contracts(): void
    {
        $this->getJson('/api/v1/admin/contracts')->assertStatus(401);
    }

    public function test_admin_can_list_contracts(): void
    {
        $ctx = $this->activeContractWithItem();

        $response = $this->adminListContracts($ctx['admin']['access_token']);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertGreaterThanOrEqual(1, count($response->json('data.contracts')));
        $this->assertArrayHasKey('pagination', $response->json('data'));
    }

    public function test_admin_can_filter_contracts_by_status(): void
    {
        $ctx = $this->activeContractWithItem();

        $response = $this->adminListContracts($ctx['admin']['access_token'], ['status' => 'ACTIVE']);

        $response->assertStatus(200);
        foreach ($response->json('data.contracts') as $contract) {
            $this->assertSame('ACTIVE', $contract['status']);
        }
    }

    public function test_admin_detail_exposes_operational_fields_customer_detail_does_not(): void
    {
        $ctx = $this->activeContractWithItem();

        $response = $this->adminGetContract($ctx['admin']['access_token'], UuidBinary::toString($ctx['contract']->id));

        $response->assertStatus(200);
        $this->assertArrayHasKey('customer', $response->json('data.contract'));
        $this->assertArrayHasKey('internal_note', $response->json('data.contract'));
        $this->assertArrayHasKey('requested_service_uuids', $response->json('data.contract'));
        $this->assertArrayHasKey('status_history', $response->json('data.contract'));
    }

    public function test_admin_get_with_malformed_uuid_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin();

        $response = $this->adminGetContract($admin['access_token'], 'not-a-uuid');

        $response->assertStatus(404);
    }

    public function test_approving_a_contract_creates_an_audit_log_entry(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $service = $this->createSubscriptionEligibleService();

        $created = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(201);

        $admin = $this->createAndLoginAdmin();
        $contractUuid = $created->json('data.contract.uuid');

        $this->adminApproveContract($admin['access_token'], $contractUuid, $this->approveContractPayload($service['uuid']))->assertStatus(200);

        $logs = $this->auditLogsFor($contractUuid);

        $this->assertCount(1, $logs);
        $this->assertSame('CONTRACT_APPROVED', $logs->first()->action_code);
        $this->assertSame('SERVICE_CONTRACT', $logs->first()->entity_type);
    }

    public function test_idempotent_approve_retry_does_not_duplicate_audit_log(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $service = $this->createSubscriptionEligibleService();

        $created = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(201);

        $admin = $this->createAndLoginAdmin();
        $contractUuid = $created->json('data.contract.uuid');
        $payload = $this->approveContractPayload($service['uuid']);

        $this->adminApproveContract($admin['access_token'], $contractUuid, $payload)->assertStatus(200);
        $this->adminApproveContract($admin['access_token'], $contractUuid, $payload)->assertStatus(200);

        $this->assertCount(1, $this->auditLogsFor($contractUuid));
    }

    public function test_suspending_a_contract_creates_an_audit_log_entry(): void
    {
        $ctx = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($ctx['contract']->id);

        $this->adminSuspendContract($ctx['admin']['access_token'], $contractUuid, ['reason' => 'QA suspend.'])->assertStatus(200);

        $logs = $this->auditLogsFor($contractUuid);
        $this->assertTrue($logs->contains(fn ($log) => $log->action_code === 'CONTRACT_SUSPENDED'));
    }

    public function test_cancelling_a_contract_creates_an_audit_log_entry(): void
    {
        $ctx = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($ctx['contract']->id);

        $this->adminCancelContract($ctx['admin']['access_token'], $contractUuid, ['reason' => 'QA cancel.'])->assertStatus(200);

        $logs = $this->auditLogsFor($contractUuid);
        $this->assertTrue($logs->contains(fn ($log) => $log->action_code === 'CONTRACT_CANCELLED'));
    }
}
