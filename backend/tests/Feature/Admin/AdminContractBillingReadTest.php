<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B5 - read-only global Admin Contract Billing visibility
 * (App\Actions\Admin\ContractBilling\AdminListContractBillingsAction/
 * AdminGetContractBillingAction / App\Support\Admin\
 * AdminContractBillingPresenter). Mirrors AdminContractTest's fixture
 * conventions exactly (activeContractWithItem() drives a real
 * request -> approve -> accept -> Stripe Checkout -> webhook flow, never a
 * shortcut direct-DB insert). No mutation endpoint exists for this module.
 */
class AdminContractBillingReadTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_admin_can_list_contract_billings(): void
    {
        $ctx = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($ctx['contract']->id);
        $billing = $this->billingRow($contractUuid);

        $response = $this->getJson('/api/v1/admin/contract-billings', $this->bearer($ctx['admin']['access_token']));

        $response->assertStatus(200)->assertJson(['success' => true]);
        $uuids = collect($response->json('data.contract_billings'))->pluck('uuid')->all();
        $this->assertContains(UuidBinary::toString($billing->id), $uuids);
    }

    public function test_super_admin_can_list_contract_billings(): void
    {
        $ctx = $this->activeContractWithItem();
        $superAdmin = $this->createAndLoginAdmin(['SUPER_ADMIN']);

        $this->getJson('/api/v1/admin/contract-billings', $this->bearer($superAdmin['access_token']))
            ->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_customer_cannot_list_admin_contract_billings(): void
    {
        $ctx = $this->activeContractWithItem();

        $this->getJson('/api/v1/admin/contract-billings', $this->bearer($ctx['customer']['access_token']))
            ->assertStatus(401);
    }

    public function test_unauthenticated_request_cannot_list_contract_billings(): void
    {
        $this->getJson('/api/v1/admin/contract-billings')->assertStatus(401);
    }

    public function test_pagination_shape_is_present(): void
    {
        $ctx = $this->activeContractWithItem();

        $response = $this->getJson('/api/v1/admin/contract-billings?per_page=1&page=1', $this->bearer($ctx['admin']['access_token']));

        $response->assertStatus(200);
        $this->assertLessThanOrEqual(1, count($response->json('data.contract_billings')));
        $this->assertSame(1, $response->json('data.pagination.per_page'));
    }

    public function test_status_filter_only_returns_matching_billings(): void
    {
        $ctx = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($ctx['contract']->id);
        $billing = $this->billingRow($contractUuid);

        $matching = $this->getJson('/api/v1/admin/contract-billings?status=ACTIVE', $this->bearer($ctx['admin']['access_token']))
            ->assertStatus(200);
        $this->assertContains(
            UuidBinary::toString($billing->id),
            collect($matching->json('data.contract_billings'))->pluck('uuid')->all(),
        );

        $nonMatching = $this->getJson('/api/v1/admin/contract-billings?status=PAST_DUE', $this->bearer($ctx['admin']['access_token']))
            ->assertStatus(200);
        $this->assertNotContains(
            UuidBinary::toString($billing->id),
            collect($nonMatching->json('data.contract_billings'))->pluck('uuid')->all(),
        );
    }

    public function test_contract_number_filter_returns_exactly_that_billing(): void
    {
        $ctx = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($ctx['contract']->id);
        $billing = $this->billingRow($contractUuid);

        $response = $this->getJson(
            '/api/v1/admin/contract-billings?contract_number='.$ctx['contract']->contract_number,
            $this->bearer($ctx['admin']['access_token']),
        );

        $uuids = collect($response->json('data.contract_billings'))->pluck('uuid')->all();
        $this->assertSame([UuidBinary::toString($billing->id)], $uuids);
    }

    public function test_customer_uuid_filter_scopes_to_that_customer_only(): void
    {
        $ctxA = $this->activeContractWithItem();
        $ctxB = $this->activeContractWithItem();
        $billingA = $this->billingRow(UuidBinary::toString($ctxA['contract']->id));
        $billingB = $this->billingRow(UuidBinary::toString($ctxB['contract']->id));

        $response = $this->getJson(
            '/api/v1/admin/contract-billings?customer_uuid='.$ctxA['customer']['user_uuid'],
            $this->bearer($ctxA['admin']['access_token']),
        );

        $uuids = collect($response->json('data.contract_billings'))->pluck('uuid')->all();
        $this->assertContains(UuidBinary::toString($billingA->id), $uuids);
        $this->assertNotContains(UuidBinary::toString($billingB->id), $uuids);
    }

    public function test_admin_can_view_contract_billing_detail(): void
    {
        $ctx = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($ctx['contract']->id);
        $billing = $this->billingRow($contractUuid);

        $response = $this->getJson(
            '/api/v1/admin/contract-billings/'.UuidBinary::toString($billing->id),
            $this->bearer($ctx['admin']['access_token']),
        );

        $response->assertStatus(200)->assertJson(['success' => true]);
        $data = $response->json('data.contract_billing');
        $this->assertSame('ACTIVE', $data['status']);
        $this->assertSame($contractUuid, $data['contract']['uuid']);
        $this->assertSame($ctx['contract']->contract_number, $data['contract']['contract_number']);
        $this->assertSame($ctx['customer']['user_uuid'], $data['customer']['uuid']);
        $this->assertIsArray($data['recent_webhook_events']);
    }

    public function test_malformed_billing_uuid_returns_404(): void
    {
        $ctx = $this->activeContractWithItem();

        $this->getJson('/api/v1/admin/contract-billings/not-a-uuid', $this->bearer($ctx['admin']['access_token']))
            ->assertStatus(404);
    }

    public function test_unknown_billing_uuid_returns_404(): void
    {
        $ctx = $this->activeContractWithItem();

        $this->getJson('/api/v1/admin/contract-billings/'.UuidBinary::generate(), $this->bearer($ctx['admin']['access_token']))
            ->assertStatus(404);
    }

    public function test_customer_cannot_view_billing_detail(): void
    {
        $ctx = $this->activeContractWithItem();
        $billing = $this->billingRow(UuidBinary::toString($ctx['contract']->id));

        $this->getJson(
            '/api/v1/admin/contract-billings/'.UuidBinary::toString($billing->id),
            $this->bearer($ctx['customer']['access_token']),
        )->assertStatus(401);
    }

    public function test_billing_detail_never_exposes_secret_material(): void
    {
        $ctx = $this->activeContractWithItem();
        $billing = $this->billingRow(UuidBinary::toString($ctx['contract']->id));

        $response = $this->getJson(
            '/api/v1/admin/contract-billings/'.UuidBinary::toString($billing->id),
            $this->bearer($ctx['admin']['access_token']),
        );

        $json = json_encode($response->json());

        foreach (['client_secret', 'webhook_secret', 'payload_hash', 'secret_key'] as $forbiddenKey) {
            $this->assertStringNotContainsString($forbiddenKey, $json, "Response must never contain {$forbiddenKey}.");
        }
    }
}
