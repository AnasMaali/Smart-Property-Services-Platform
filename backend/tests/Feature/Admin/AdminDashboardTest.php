<?php

namespace Tests\Feature\Admin;

use App\Support\Contract\Billing\ContractBillingStatuses;
use App\Support\Contract\ContractStatuses;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\Feature\Technician\Concerns\CreatesTechnicianFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B10 - Admin Dashboard (App\Actions\Admin\Dashboard\
 * AdminGetDashboardAction / App\Support\Admin\AdminDashboardPresenter).
 * Every metric/attention/activity assertion here is checked directly
 * against the exact canonical rows the Action reads - never a second,
 * independently-computed expectation.
 */
class AdminDashboardTest extends TestCase
{
    use CreatesContractFixtures;
    use CreatesTechnicianFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    private function dashboardUrl(): string
    {
        return '/api/v1/admin/dashboard';
    }

    // -----------------------------------------------------------------
    // AUTHORIZATION
    // -----------------------------------------------------------------

    public function test_unauthenticated_request_cannot_access_dashboard(): void
    {
        $this->getJson($this->dashboardUrl())->assertStatus(401);
    }

    public function test_customer_cannot_access_dashboard(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->getJson($this->dashboardUrl(), $this->bearer($customer['access_token']))->assertStatus(401);
    }

    public function test_admin_can_access_dashboard(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson($this->dashboardUrl(), $this->bearer($admin['access_token']))
            ->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_super_admin_can_access_dashboard(): void
    {
        $admin = $this->createAndLoginAdmin(['SUPER_ADMIN']);

        $this->getJson($this->dashboardUrl(), $this->bearer($admin['access_token']))
            ->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_admin_without_dashboard_capability_is_denied(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'dashboard.view')->value('id');

        DB::table('admin_role_permissions')->where('role_id', $adminRoleId)->where('permission_id', $permissionId)->delete();

        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson($this->dashboardUrl(), $this->bearer($admin['access_token']))->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // ZERO STATE
    // -----------------------------------------------------------------

    public function test_zero_state_returns_valid_zeros_and_empty_lists(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->getJson($this->dashboardUrl(), $this->bearer($admin['access_token']));
        $data = $response->json('data');

        foreach ($data['summary'] as $group => $metrics) {
            foreach ($metrics as $metric => $value) {
                $this->assertSame(0, $value, "{$group}.{$metric} should be 0 in a zero-state database.");
            }
        }

        foreach ($data['attention'] as $key => $items) {
            $this->assertSame([], $items, "attention.{$key} should be empty in a zero-state database.");
        }

        $this->assertSame([], $data['recent_activity']);
    }

    // -----------------------------------------------------------------
    // SUMMARY ACCURACY
    // -----------------------------------------------------------------

    public function test_booking_and_financial_summary_counts_are_accurate(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();

        $response = $this->getJson($this->dashboardUrl(), $this->bearer($admin['access_token']));
        $summary = $response->json('data.summary');

        $this->assertSame(1, $summary['bookings']['active']);
        $this->assertSame(1, $summary['bookings']['created_last_24h']);
        $this->assertSame(1, $summary['bookings']['pending_assignment']);
        $this->assertSame(0, $summary['bookings']['in_progress']);
        $this->assertSame(1, $summary['financial']['payments_successful_last_24h']);

        $attentionItems = $response->json('data.attention.booking_items_pending_assignment');
        $this->assertCount(1, $attentionItems);
        $this->assertSame(UuidBinary::toString($fixture['booking']->id), $attentionItems[0]['booking_uuid']);
    }

    public function test_contract_summary_and_attention_are_accurate(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $contractUuid = $this->createRequestedContract($customer['user_uuid'], $property['uuid']);

        $response = $this->getJson($this->dashboardUrl(), $this->bearer($admin['access_token']));
        $summary = $response->json('data.summary');

        $this->assertSame(1, $summary['contracts']['awaiting_approval']);

        $attentionItems = $response->json('data.attention.contracts_awaiting_approval');
        $this->assertCount(1, $attentionItems);
        $this->assertSame($contractUuid, $attentionItems[0]['contract_uuid']);
    }

    public function test_billing_past_due_summary_and_attention_are_accurate(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $ctx = $this->activeContractWithItem();

        $billing = DB::table('service_contract_billings')->where('service_contract_id', $ctx['contract']->id)->first();
        DB::table('service_contract_billings')->where('id', $billing->id)->update([
            'status_id' => ContractBillingStatuses::id('PAST_DUE'),
            'past_due_since' => $billing->created_at,
        ]);

        $response = $this->getJson($this->dashboardUrl(), $this->bearer($admin['access_token']));
        $summary = $response->json('data.summary');

        $this->assertSame(1, $summary['financial']['billings_past_due']);

        $attentionItems = $response->json('data.attention.billings_past_due');
        $this->assertCount(1, $attentionItems);
        $this->assertSame(UuidBinary::toString($billing->id), $attentionItems[0]['billing_uuid']);
        $this->assertSame($ctx['contract']->contract_number, $attentionItems[0]['contract_number']);
    }

    public function test_payment_reconciliation_summary_and_attention_are_accurate(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->readyForPaymentCustomer();
        $createResponse = $this->createPayment($customer['access_token'], UuidBinary::generate());
        $paymentUuid = $createResponse->json('data.payment.uuid');

        DB::table('payment_attempts')->where('id', UuidBinary::toBinary($paymentUuid))->update([
            'requires_reconciliation' => 1,
            'reconciliation_reason_code' => 'UNEXPECTED_PROVIDER_STATE',
        ]);

        $response = $this->getJson($this->dashboardUrl(), $this->bearer($admin['access_token']));
        $summary = $response->json('data.summary');

        $this->assertSame(1, $summary['financial']['payments_requiring_reconciliation']);
        $this->assertGreaterThanOrEqual(1, $summary['financial']['payments_pending']);

        $attentionItems = $response->json('data.attention.payments_requiring_reconciliation');
        $this->assertCount(1, $attentionItems);
        $this->assertSame($paymentUuid, $attentionItems[0]['payment_uuid']);
        $this->assertSame('UNEXPECTED_PROVIDER_STATE', $attentionItems[0]['reconciliation_reason_code']);
    }

    public function test_support_summary_and_attention_are_accurate(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();

        $openStatusId = DB::table('support_request_statuses')->where('code', 'OPEN')->value('id');
        $now = now();
        $supportUuid = UuidBinary::generate();

        DB::table('support_requests')->insert([
            'id' => UuidBinary::toBinary($supportUuid),
            'request_number' => 'SUP-DASH-QA-1',
            'customer_user_id' => UuidBinary::toBinary($customer['user_uuid']),
            'booking_id' => null,
            'status_id' => $openStatusId,
            'assigned_admin_user_id' => null,
            'subject' => 'Dashboard QA subject',
            'status_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->getJson($this->dashboardUrl(), $this->bearer($admin['access_token']));
        $summary = $response->json('data.summary');

        $this->assertSame(1, $summary['support']['open_or_in_progress']);
        $this->assertSame(1, $summary['support']['unassigned_open']);

        $attentionItems = $response->json('data.attention.support_unassigned_open');
        $this->assertCount(1, $attentionItems);
        $this->assertSame($supportUuid, $attentionItems[0]['support_request_uuid']);
        $this->assertSame('SUP-DASH-QA-1', $attentionItems[0]['request_number']);
    }

    public function test_technician_summary_is_accurate(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->createEligibleTechnician($this->createSpecialization());

        $response = $this->getJson($this->dashboardUrl(), $this->bearer($admin['access_token']));
        $summary = $response->json('data.summary');

        $this->assertSame(1, $summary['technicians']['assignable']);
        $this->assertSame(0, $summary['technicians']['busy']);
    }

    public function test_customer_summary_is_accurate(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->createAuthenticatedCartCustomer();

        $response = $this->getJson($this->dashboardUrl(), $this->bearer($admin['access_token']));
        $summary = $response->json('data.summary');

        $this->assertSame(1, $summary['customers']['active']);
        $this->assertSame(1, $summary['customers']['registered_last_24h']);
    }

    // -----------------------------------------------------------------
    // RECENT ACTIVITY
    // -----------------------------------------------------------------

    public function test_recent_activity_is_bounded_and_deterministically_ordered(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $actorId = UuidBinary::toBinary($admin['user_uuid']);

        for ($i = 0; $i < 15; $i++) {
            DB::table('admin_audit_logs')->insert([
                'id' => UuidBinary::toBinary(UuidBinary::generate()),
                'admin_user_id' => $actorId,
                'action_code' => 'QA_DASHBOARD_EVENT',
                'entity_type' => 'QA_ENTITY',
                'entity_identifier' => (string) $i,
                'was_successful' => 1,
                'created_at' => now()->subSeconds(15 - $i),
            ]);
        }

        $response = $this->getJson($this->dashboardUrl(), $this->bearer($admin['access_token']));
        $activity = $response->json('data.recent_activity');

        $this->assertCount(10, $activity);
        $this->assertSame('14', $activity[0]['entity_identifier']);
        $this->assertSame('5', $activity[9]['entity_identifier']);
    }

    public function test_recent_activity_reflects_a_real_admin_mutation_safely(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();

        $this->patchJson('/api/v1/admin/service-categories/'.$categoryId, [
            'name' => 'Dashboard QA Category',
            'description' => null,
            'display_order' => 1,
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $response = $this->getJson($this->dashboardUrl(), $this->bearer($admin['access_token']));
        $activity = collect($response->json('data.recent_activity'));

        $entry = $activity->firstWhere('action_code', 'SERVICE_CATEGORY_UPDATED');
        $this->assertNotNull($entry);
        $this->assertSame('SERVICE_CATEGORY', $entry['entity_type']);
        $this->assertSame((string) $categoryId, $entry['entity_identifier']);
        $this->assertTrue($entry['was_successful']);
        $this->assertArrayNotHasKey('old_values', $entry);
        $this->assertArrayNotHasKey('new_values', $entry);
    }

    // -----------------------------------------------------------------
    // SAFETY
    // -----------------------------------------------------------------

    public function test_dashboard_never_exposes_security_material_or_raw_binary_ids(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->bookingWithAssignableItem();
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $this->createRequestedContract($customer['user_uuid'], $property['uuid']);

        $response = $this->getJson($this->dashboardUrl(), $this->bearer($admin['access_token']));
        $json = json_encode($response->json());

        foreach (['password_hash', 'refresh_token_hash', 'client_secret', 'checkout_snapshot', 'stripe_', 'old_values', 'new_values'] as $forbiddenKey) {
            $this->assertStringNotContainsString($forbiddenKey, $json, "Response must never contain {$forbiddenKey}.");
        }
    }

    private function createRequestedContract(string $customerUuid, string $propertyUuid): string
    {
        $now = now();
        $contractUuid = UuidBinary::generate();

        DB::table('service_contracts')->insert([
            'id' => UuidBinary::toBinary($contractUuid),
            'contract_number' => 'CON-DASH-QA-'.substr($contractUuid, 0, 8),
            'customer_user_id' => UuidBinary::toBinary($customerUuid),
            'customer_property_id' => UuidBinary::toBinary($propertyUuid),
            'status_id' => ContractStatuses::id('REQUESTED'),
            'status_changed_at' => $now,
            'requested_all_services' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $contractUuid;
    }
}
