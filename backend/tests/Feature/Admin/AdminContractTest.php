<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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

        // BLUE V1 Phase A2.5 - contracts.cancel now also requires a fresh
        // WebAuthn step-up; grant it directly for this session rather than
        // driving the real ceremony, exactly like activeContractWithItem()
        // itself mints the Admin session directly instead of via HTTP.
        $this->markStepUpVerified($ctx['admin']['session_uuid']);

        $this->adminCancelContract($ctx['admin']['access_token'], $contractUuid, ['reason' => 'QA cancel.'])->assertStatus(200);

        $logs = $this->auditLogsFor($contractUuid);
        $this->assertTrue($logs->contains(fn ($log) => $log->action_code === 'CONTRACT_CANCELLED'));
    }

    public function test_customer_token_cannot_invoke_other_admin_contract_mutations(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $service = $this->createSubscriptionEligibleService();

        $created = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(201);

        $contractUuid = $created->json('data.contract.uuid');

        foreach ([
            'send-for-acceptance',
            'suspend',
            'cancel',
        ] as $operation) {
            $this->postJson(
                '/api/v1/admin/contracts/'.$contractUuid.'/'.$operation,
                ['reason' => 'Unauthorized customer mutation.'],
                ['Authorization' => 'Bearer '.$customer['access_token']]
            )->assertStatus(401);
        }
    }

    public function test_malformed_admin_contract_mutation_uuids_return_clean_404(): void
    {
        $admin = $this->createAndLoginAdmin();
        // BLUE V1 Phase A2.5 - the cancel case below is admin.stepup-
        // protected; grant it up front so all four operations reach their
        // own (identical) 404 behavior rather than a 428 for cancel alone.
        $this->markStepUpVerified($admin['session_uuid']);
        $service = $this->createSubscriptionEligibleService();

        $cases = [
            'approve' => $this->approveContractPayload($service['uuid']),
            'send-for-acceptance' => [],
            'suspend' => ['reason' => 'QA suspend.'],
            'cancel' => ['reason' => 'QA cancel.'],
        ];

        foreach ($cases as $operation => $payload) {
            $this->postJson(
                '/api/v1/admin/contracts/not-a-uuid/'.$operation,
                $payload,
                ['Authorization' => 'Bearer '.$admin['access_token']]
            )->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'message' => 'Service contract not found.',
                ]);
        }
    }

    public function test_unknown_admin_contract_mutation_uuids_return_clean_404(): void
    {
        $admin = $this->createAndLoginAdmin();
        // BLUE V1 Phase A2.5 - see identical note in
        // test_malformed_admin_contract_mutation_uuids_return_clean_404.
        $this->markStepUpVerified($admin['session_uuid']);
        $service = $this->createSubscriptionEligibleService();

        $cases = [
            'approve' => $this->approveContractPayload($service['uuid']),
            'send-for-acceptance' => [],
            'suspend' => ['reason' => 'QA suspend.'],
            'cancel' => ['reason' => 'QA cancel.'],
        ];

        foreach ($cases as $operation => $payload) {
            $this->postJson(
                '/api/v1/admin/contracts/'.UuidBinary::generate().'/'.$operation,
                $payload,
                ['Authorization' => 'Bearer '.$admin['access_token']]
            )->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'message' => 'Service contract not found.',
                ]);
        }
    }

    public function test_contract_audit_actor_is_derived_from_authenticated_admin_not_request_body(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $service = $this->createSubscriptionEligibleService();

        $created = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(201);

        $contractUuid = $created->json('data.contract.uuid');

        $realAdmin = $this->createAndLoginAdmin();
        $spoofedAdmin = $this->createAndLoginAdmin();

        $payload = $this->approveContractPayload($service['uuid']);
        $payload['admin_user_id'] = $spoofedAdmin['user_uuid'];
        $payload['actor_uuid'] = $spoofedAdmin['user_uuid'];
        $payload['changed_by_user_id'] = $spoofedAdmin['user_uuid'];

        $this->adminApproveContract(
            $realAdmin['access_token'],
            $contractUuid,
            $payload
        )->assertStatus(200);

        $audit = DB::table('admin_audit_logs')
            ->where('entity_identifier', $contractUuid)
            ->where('action_code', 'CONTRACT_APPROVED')
            ->first();

        $this->assertNotNull($audit);

        $this->assertSame(
            UuidBinary::toBinary($realAdmin['user_uuid']),
            $audit->admin_user_id
        );

        $this->assertNotSame(
            UuidBinary::toBinary($spoofedAdmin['user_uuid']),
            $audit->admin_user_id
        );
    }

    public function test_send_for_acceptance_retry_does_not_duplicate_audit_log(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $service = $this->createSubscriptionEligibleService();

        $created = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(201);

        $contractUuid = $created->json('data.contract.uuid');
        $admin = $this->createAndLoginAdmin();

        $this->adminApproveContract(
            $admin['access_token'],
            $contractUuid,
            $this->approveContractPayload($service['uuid'])
        )->assertStatus(200);

        $this->adminSendContractForAcceptance(
            $admin['access_token'],
            $contractUuid
        )->assertStatus(200);

        $this->adminSendContractForAcceptance(
            $admin['access_token'],
            $contractUuid
        )->assertStatus(200);

        $count = DB::table('admin_audit_logs')
            ->where('entity_identifier', $contractUuid)
            ->where('action_code', 'CONTRACT_SENT_FOR_ACCEPTANCE')
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_suspend_retry_does_not_duplicate_audit_log(): void
    {
        $ctx = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($ctx['contract']->id);

        $this->adminSuspendContract(
            $ctx['admin']['access_token'],
            $contractUuid,
            ['reason' => 'QA suspend.']
        )->assertStatus(200);

        $this->adminSuspendContract(
            $ctx['admin']['access_token'],
            $contractUuid,
            ['reason' => 'QA suspend retry.']
        )->assertStatus(200);

        $count = DB::table('admin_audit_logs')
            ->where('entity_identifier', $contractUuid)
            ->where('action_code', 'CONTRACT_SUSPENDED')
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_cancel_retry_does_not_duplicate_audit_log(): void
    {
        $ctx = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($ctx['contract']->id);

        // BLUE V1 Phase A2.5 - one grant covers both cancel calls below;
        // step-up is a reusable freshness window, not consumed per action.
        $this->markStepUpVerified($ctx['admin']['session_uuid']);

        $this->adminCancelContract(
            $ctx['admin']['access_token'],
            $contractUuid,
            ['reason' => 'QA cancel.']
        )->assertStatus(200);

        $this->adminCancelContract(
            $ctx['admin']['access_token'],
            $contractUuid,
            ['reason' => 'QA cancel retry.']
        )->assertStatus(200);

        $count = DB::table('admin_audit_logs')
            ->where('entity_identifier', $contractUuid)
            ->where('action_code', 'CONTRACT_CANCELLED')
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_rejected_contract_mutation_writes_no_success_audit_log(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $service = $this->createSubscriptionEligibleService();

        $created = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(201);

        $contractUuid = $created->json('data.contract.uuid');
        $admin = $this->createAndLoginAdmin();

        // REQUESTED cannot be suspended.
        $this->adminSuspendContract(
            $admin['access_token'],
            $contractUuid,
            ['reason' => 'This mutation must be rejected.']
        )->assertStatus(409);

        $count = DB::table('admin_audit_logs')
            ->where('entity_identifier', $contractUuid)
            ->where('action_code', 'CONTRACT_SUSPENDED')
            ->count();

        $this->assertSame(0, $count);
    }
}
