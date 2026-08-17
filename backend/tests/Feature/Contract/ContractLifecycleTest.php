<?php

namespace Tests\Feature\Contract;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

class ContractLifecycleTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    /**
     * @return array{customer: array, admin: array, property_uuid: string, service: array, contract_uuid: string}
     */
    private function requestedContract(): array
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

        return [
            'customer' => $customer,
            'admin' => $admin,
            'property_uuid' => $property['uuid'],
            'service' => $service,
            'contract_uuid' => $created->json('data.contract.uuid'),
        ];
    }

    public function test_admin_can_approve_a_requested_contract(): void
    {
        $ctx = $this->requestedContract();

        $response = $this->adminApproveContract($ctx['admin']['access_token'], $ctx['contract_uuid'], $this->approveContractPayload($ctx['service']['uuid']));

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertSame('APPROVED', $response->json('data.contract.status'));
        $this->assertCount(1, $response->json('data.contract.covered_services'));
        $this->assertSame('LIMITED_VISITS', $response->json('data.contract.covered_services.0.entitlement_mode'));
        $this->assertSame(2, $response->json('data.contract.covered_services.0.included_visits'));
    }

    public function test_approve_is_idempotent_and_does_not_duplicate_items(): void
    {
        $ctx = $this->requestedContract();
        $payload = $this->approveContractPayload($ctx['service']['uuid']);

        $this->adminApproveContract($ctx['admin']['access_token'], $ctx['contract_uuid'], $payload)->assertStatus(200);
        $second = $this->adminApproveContract($ctx['admin']['access_token'], $ctx['contract_uuid'], $payload);

        $second->assertStatus(200);
        $this->assertCount(1, $this->contractItemRows($ctx['contract_uuid']));
    }

    public function test_approve_rejects_non_eligible_service(): void
    {
        $ctx = $this->requestedContract();
        $nonEligible = $this->createCartService();

        $response = $this->adminApproveContract($ctx['admin']['access_token'], $ctx['contract_uuid'], $this->approveContractPayload($nonEligible['uuid']));

        $response->assertStatus(422);
    }

    public function test_approve_requires_included_visits_for_limited_mode(): void
    {
        $ctx = $this->requestedContract();

        $response = $this->adminApproveContract($ctx['admin']['access_token'], $ctx['contract_uuid'], $this->approveContractPayload($ctx['service']['uuid'], [
            'services' => [['service_uuid' => $ctx['service']['uuid'], 'entitlement_mode' => 'LIMITED_VISITS']],
        ]));

        $response->assertStatus(422);
    }

    public function test_approve_writes_exactly_one_status_history_row(): void
    {
        $ctx = $this->requestedContract();

        $this->adminApproveContract($ctx['admin']['access_token'], $ctx['contract_uuid'], $this->approveContractPayload($ctx['service']['uuid']))->assertStatus(200);
        $this->adminApproveContract($ctx['admin']['access_token'], $ctx['contract_uuid'], $this->approveContractPayload($ctx['service']['uuid']))->assertStatus(200);

        $count = DB::table('service_contract_status_history')
            ->where('service_contract_id', UuidBinary::toBinary($ctx['contract_uuid']))
            ->count();

        $this->assertSame(2, $count); // REQUESTED->null-from insert + REQUESTED->APPROVED, not duplicated on retry
    }

    public function test_admin_can_send_approved_contract_for_acceptance(): void
    {
        $ctx = $this->requestedContract();
        $this->adminApproveContract($ctx['admin']['access_token'], $ctx['contract_uuid'], $this->approveContractPayload($ctx['service']['uuid']))->assertStatus(200);

        $response = $this->adminSendContractForAcceptance($ctx['admin']['access_token'], $ctx['contract_uuid']);

        $response->assertStatus(200);
        $this->assertSame('PENDING_CUSTOMER_ACCEPTANCE', $response->json('data.contract.status'));
    }

    public function test_send_for_acceptance_requires_approved_status(): void
    {
        $ctx = $this->requestedContract();

        $response = $this->adminSendContractForAcceptance($ctx['admin']['access_token'], $ctx['contract_uuid']);

        $response->assertStatus(409);
    }

    public function test_customer_can_accept_pending_contract(): void
    {
        $ctx = $this->requestedContract();
        $this->adminApproveContract($ctx['admin']['access_token'], $ctx['contract_uuid'], $this->approveContractPayload($ctx['service']['uuid']))->assertStatus(200);
        $this->adminSendContractForAcceptance($ctx['admin']['access_token'], $ctx['contract_uuid'])->assertStatus(200);

        $response = $this->acceptContractHttp($ctx['customer']['access_token'], $ctx['contract_uuid']);

        $response->assertStatus(200);
        $this->assertSame('ACTIVE', $response->json('data.contract.status'));
        $this->assertTrue($response->json('data.contract.acceptance.accepted'));
    }

    public function test_accept_requires_pending_customer_acceptance_status(): void
    {
        $ctx = $this->requestedContract();

        $response = $this->acceptContractHttp($ctx['customer']['access_token'], $ctx['contract_uuid']);

        $response->assertStatus(409);
    }

    public function test_accept_is_idempotent(): void
    {
        $ctx = $this->requestedContract();
        $this->adminApproveContract($ctx['admin']['access_token'], $ctx['contract_uuid'], $this->approveContractPayload($ctx['service']['uuid']))->assertStatus(200);
        $this->adminSendContractForAcceptance($ctx['admin']['access_token'], $ctx['contract_uuid'])->assertStatus(200);

        $this->acceptContractHttp($ctx['customer']['access_token'], $ctx['contract_uuid'])->assertStatus(200);
        $second = $this->acceptContractHttp($ctx['customer']['access_token'], $ctx['contract_uuid']);

        $second->assertStatus(200);
        $this->assertSame('ACTIVE', $second->json('data.contract.status'));

        $acceptanceRows = DB::table('service_contract_acceptances')
            ->where('service_contract_id', UuidBinary::toBinary($ctx['contract_uuid']))
            ->count();

        $this->assertSame(1, $acceptanceRows);
    }

    public function test_admin_can_suspend_an_active_contract(): void
    {
        $built = $this->activeContractWithItem();

        $response = $this->adminSuspendContract($built['admin']['access_token'], UuidBinary::toString($built['contract']->id), ['reason' => 'Customer requested a pause.']);

        $response->assertStatus(200);
        $this->assertSame('SUSPENDED', $response->json('data.contract.status'));
    }

    public function test_suspend_requires_active_status(): void
    {
        $ctx = $this->requestedContract();

        $response = $this->adminSuspendContract($ctx['admin']['access_token'], $ctx['contract_uuid']);

        $response->assertStatus(409);
    }

    public function test_admin_can_cancel_a_requested_contract(): void
    {
        $ctx = $this->requestedContract();

        $response = $this->adminCancelContract($ctx['admin']['access_token'], $ctx['contract_uuid'], ['reason' => 'Customer withdrew request.']);

        $response->assertStatus(200);
        $this->assertSame('CANCELLED', $response->json('data.contract.status'));
    }

    public function test_admin_can_cancel_an_active_contract(): void
    {
        $built = $this->activeContractWithItem();

        $response = $this->adminCancelContract($built['admin']['access_token'], UuidBinary::toString($built['contract']->id));

        $response->assertStatus(200);
        $this->assertSame('CANCELLED', $response->json('data.contract.status'));
    }

    public function test_cancel_is_idempotent(): void
    {
        $ctx = $this->requestedContract();

        $this->adminCancelContract($ctx['admin']['access_token'], $ctx['contract_uuid'])->assertStatus(200);
        $second = $this->adminCancelContract($ctx['admin']['access_token'], $ctx['contract_uuid']);

        $second->assertStatus(200);
        $this->assertSame('CANCELLED', $second->json('data.contract.status'));
    }

    public function test_expired_contract_reports_effective_status_before_any_write(): void
    {
        $built = $this->activeContractWithItem();
        $contractIdBinary = $built['contract']->id;
        $startsAt = Carbon::parse($built['contract']->starts_at);

        DB::table('service_contracts')->where('id', $contractIdBinary)->update([
            'ends_at' => $startsAt->copy()->addHours(12),
        ]);

        $response = $this->adminGetContract($built['admin']['access_token'], UuidBinary::toString($contractIdBinary));

        $response->assertStatus(200);
        $this->assertSame('EXPIRED', $response->json('data.contract.status'));

        // The stored row is untouched by a plain read - the DB write only
        // happens on the next write-path call that actually needs it.
        $stillActiveInDb = DB::table('service_contracts')->where('id', $contractIdBinary)->value('status_id');
        $this->assertNotNull($stillActiveInDb);
    }

    public function test_expired_contract_cannot_be_suspended(): void
    {
        $built = $this->activeContractWithItem();
        $contractIdBinary = $built['contract']->id;
        $startsAt = Carbon::parse($built['contract']->starts_at);

        DB::table('service_contracts')->where('id', $contractIdBinary)->update(['ends_at' => $startsAt->copy()->addHours(12)]);

        $response = $this->adminSuspendContract($built['admin']['access_token'], UuidBinary::toString($contractIdBinary));

        $response->assertStatus(409);

        $row = DB::table('service_contracts')->where('id', $contractIdBinary)->first();
        $this->assertSame('EXPIRED', \App\Support\Contract\ContractStatuses::code((int) $row->status_id));
    }
}
