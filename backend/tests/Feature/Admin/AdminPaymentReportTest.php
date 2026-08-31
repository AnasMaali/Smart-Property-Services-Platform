<?php

namespace Tests\Feature\Admin;

use App\Support\Payment\Gateway\PaymentCreationResult;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\Feature\Admin\Concerns\CreatesFinancialFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Admin Reports - Payment Report
 * (App\Actions\Admin\Reports\AdminPaymentReportAction). Unlike the
 * Financial Dashboard/Ledger (SUCCESSFUL only), this report deliberately
 * shows PENDING/FAILED attempts too - never a provider secret, credential,
 * or raw payload.
 */
class AdminPaymentReportTest extends TestCase
{
    use CreatesAdminFixtures;
    use CreatesFinancialFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    private function report(string $accessToken, array $query = []): TestResponse
    {
        $suffix = $query === [] ? '' : ('?'.http_build_query($query));

        return $this->getJson('/api/v1/admin/reports/payments'.$suffix, $this->bearer($accessToken));
    }

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->getJson('/api/v1/admin/reports/payments')->assertStatus(401);
    }

    public function test_admin_without_payments_view_is_denied(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'payments.view')->value('id');
        DB::table('admin_role_permissions')->where('role_id', $adminRoleId)->where('permission_id', $permissionId)->delete();

        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->report($admin['access_token'], ['range' => 'THIS_MONTH'])->assertStatus(403);
    }

    public function test_successful_and_failed_payments_both_appear(): void
    {
        $this->successfulPayment();

        $customer = $this->readyForPaymentCustomer();
        $this->fakeGateway()->queueNextCreation(PaymentCreationResult::definitiveFailure('BAD_PARAMS', 'invalid'));
        $this->createPayment($customer['access_token'], (string) Str::uuid());

        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $response = $this->report($admin['access_token'], ['range' => 'THIS_MONTH']);

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('data.summary.total_payments'));
        $this->assertSame(1, $response->json('data.summary.successful_count'));
        $this->assertSame(1, $response->json('data.summary.failed_count'));
    }

    public function test_status_filter_narrows_results(): void
    {
        $this->successfulPayment();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $successful = $this->report($admin['access_token'], ['range' => 'THIS_MONTH', 'status' => 'SUCCESSFUL']);
        $this->assertCount(1, $successful->json('data.payments'));

        $failed = $this->report($admin['access_token'], ['range' => 'THIS_MONTH', 'status' => 'FAILED']);
        $this->assertCount(0, $failed->json('data.payments'));
    }

    public function test_never_exposes_provider_secrets_or_raw_ids(): void
    {
        $this->successfulPayment();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->report($admin['access_token'], ['range' => 'THIS_MONTH']);
        $row = $response->json('data.payments.0');

        $this->assertArrayNotHasKey('provider_session_reference', $row);
        $this->assertArrayNotHasKey('checkout_snapshot', $row);
        $this->assertArrayNotHasKey('checkout_reference', $row);
    }

    public function test_csv_export_never_exposes_provider_secrets(): void
    {
        $this->successfulPayment();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->get('/api/v1/admin/reports/payments/csv?range=THIS_MONTH', $this->bearer($admin['access_token']));
        $body = $response->streamedContent();

        $this->assertStringNotContainsString('client_secret', $body);
        $this->assertStringNotContainsString('cs_test_', $body);
    }

    public function test_pdf_export_returns_a_pdf(): void
    {
        $this->successfulPayment();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->get('/api/v1/admin/reports/payments/pdf?range=THIS_MONTH', $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
    }
}
