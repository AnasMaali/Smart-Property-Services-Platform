<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\Feature\Admin\Concerns\CreatesFinancialFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Admin Reports - Pay-on-Site Report
 * (App\Actions\Admin\Reports\AdminPayOnSiteReportAction). Its summary is
 * read directly from App\Support\Admin\AdminFinancialSummaryCalculator::
 * compute() - `pending_amount`/`pending_count` deliberately never scoped to
 * the caller's date range (an uncollected settlement from any period is
 * still real money outstanding today), matching the Financial Dashboard's
 * own behavior exactly.
 */
class AdminPayOnSiteReportTest extends TestCase
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

        return $this->getJson('/api/v1/admin/reports/pay-on-site'.$suffix, $this->bearer($accessToken));
    }

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->getJson('/api/v1/admin/reports/pay-on-site')->assertStatus(401);
    }

    public function test_admin_without_payments_view_is_denied(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'payments.view')->value('id');
        DB::table('admin_role_permissions')->where('role_id', $adminRoleId)->where('permission_id', $permissionId)->delete();

        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->report($admin['access_token'], ['range' => 'THIS_MONTH'])->assertStatus(403);
    }

    public function test_pending_settlement_shows_only_in_pending(): void
    {
        $this->pendingPayOnSiteBooking();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->report($admin['access_token'], ['range' => 'THIS_MONTH']);

        $response->assertStatus(200);
        $this->assertSame('0.000000', $response->json('data.summary.collected_amount'));
        $this->assertSame('100.000000', $response->json('data.summary.pending_amount'));
        $this->assertSame(1, $response->json('data.summary.pending_count'));

        $rows = $response->json('data.settlements');
        $this->assertCount(1, $rows);
        $this->assertSame('PENDING', $rows[0]['status']);
        $this->assertNull($rows[0]['amount_collected']);
    }

    public function test_collected_settlement_moves_to_collected_and_out_of_pending(): void
    {
        $this->collectedPayOnSiteBooking();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->report($admin['access_token'], ['range' => 'THIS_MONTH']);

        $this->assertSame('100.000000', $response->json('data.summary.collected_amount'));
        $this->assertSame(1, $response->json('data.summary.collected_count'));
        $this->assertSame('0.000000', $response->json('data.summary.pending_amount'));
        $this->assertSame(0, $response->json('data.summary.pending_count'));

        $rows = $response->json('data.settlements');
        $this->assertSame('COLLECTED', $rows[0]['status']);
        $this->assertSame('100.000000', $rows[0]['amount_collected']);
    }

    public function test_status_filter_narrows_results(): void
    {
        $this->pendingPayOnSiteBooking();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $pending = $this->report($admin['access_token'], ['range' => 'THIS_MONTH', 'status' => 'PENDING']);
        $this->assertCount(1, $pending->json('data.settlements'));

        $collected = $this->report($admin['access_token'], ['range' => 'THIS_MONTH', 'status' => 'COLLECTED']);
        $this->assertCount(0, $collected->json('data.settlements'));
    }

    public function test_pending_amount_summary_matches_financial_dashboard_regardless_of_range(): void
    {
        $this->pendingPayOnSiteBooking();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $dashboard = $this->getJson('/api/v1/admin/financial-dashboard?range=TODAY', $this->bearer($admin['access_token']));
        $report = $this->report($admin['access_token'], ['range' => 'THIS_MONTH']);

        $this->assertSame($dashboard->json('data.summary.breakdown.pay_on_site.pending'), $report->json('data.summary.pending_amount'));
    }

    public function test_pending_is_never_counted_as_revenue_in_summary(): void
    {
        $this->pendingPayOnSiteBooking();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->report($admin['access_token'], ['range' => 'THIS_MONTH']);

        // The pending amount only ever appears under pending_amount, never
        // folded into collected_amount.
        $this->assertSame('0.000000', $response->json('data.summary.collected_amount'));
    }

    public function test_csv_export_streams_matching_rows(): void
    {
        $this->collectedPayOnSiteBooking();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->get('/api/v1/admin/reports/pay-on-site/csv?range=THIS_MONTH', $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('COLLECTED', $response->streamedContent());
    }

    public function test_pdf_export_returns_a_pdf(): void
    {
        $this->collectedPayOnSiteBooking();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->get('/api/v1/admin/reports/pay-on-site/pdf?range=THIS_MONTH', $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
    }
}
