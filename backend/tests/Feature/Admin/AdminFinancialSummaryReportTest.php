<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\Feature\Admin\Concerns\CreatesFinancialFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Admin Reports - Financial Summary Report
 * (App\Actions\Admin\Reports\AdminFinancialSummaryReportAction). Its
 * headline totals are the exact same App\Support\Admin\
 * AdminFinancialSummaryCalculator::compute() output the Financial Dashboard
 * already returns - the core assertion this suite exists to prove is that
 * both surfaces report the identical number for the identical range, never
 * a second calculation.
 */
class AdminFinancialSummaryReportTest extends TestCase
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

        return $this->getJson('/api/v1/admin/reports/financial'.$suffix, $this->bearer($accessToken));
    }

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->getJson('/api/v1/admin/reports/financial')->assertStatus(401);
    }

    public function test_admin_without_payments_view_is_denied(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'payments.view')->value('id');
        DB::table('admin_role_permissions')->where('role_id', $adminRoleId)->where('permission_id', $permissionId)->delete();

        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->report($admin['access_token'])->assertStatus(403);
    }

    public function test_report_totals_match_the_financial_dashboard_exactly_for_the_same_range(): void
    {
        $this->successfulPayment();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $dashboard = $this->getJson('/api/v1/admin/financial-dashboard?range=THIS_MONTH', $this->bearer($admin['access_token']));
        $report = $this->report($admin['access_token'], ['range' => 'THIS_MONTH']);

        $report->assertStatus(200);
        $this->assertSame($dashboard->json('data.summary'), $report->json('data.summary'));
    }

    public function test_zero_state_returns_zero_safely(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->report($admin['access_token'], ['range' => 'THIS_MONTH']);

        $response->assertStatus(200);
        $this->assertSame('0.000000', $response->json('data.summary.gross_revenue'));

        foreach ($response->json('data.breakdown_by_day') as $day) {
            $this->assertSame('0.000000', $day['gross_revenue']);
        }
    }

    public function test_daily_breakdown_totals_sum_to_the_headline_total(): void
    {
        $fixture = $this->successfulPayment();
        $amount = (string) $fixture['payment']->confirmed_amount;
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->report($admin['access_token'], ['range' => 'THIS_MONTH']);

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.breakdown_truncated'));

        $sum = '0.000000';
        foreach ($response->json('data.breakdown_by_day') as $day) {
            $sum = bcadd($sum, $day['gross_revenue'], 6);
        }

        $this->assertSame($amount, $sum);
        $this->assertSame($amount, $response->json('data.summary.gross_revenue'));
    }

    public function test_csv_export_contains_a_total_row_matching_the_screen_total(): void
    {
        $fixture = $this->successfulPayment();
        $amount = (string) $fixture['payment']->confirmed_amount;
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->get('/api/v1/admin/reports/financial/csv?range=THIS_MONTH', $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename="blue-financial-summary-report_', $response->headers->get('Content-Disposition'));

        $body = $response->streamedContent();
        $this->assertStringContainsString('Gross Revenue', $body);
        $this->assertStringContainsString($amount, $body);
    }

    public function test_pdf_export_returns_a_pdf(): void
    {
        $this->successfulPayment();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->get('/api/v1/admin/reports/financial/pdf?range=THIS_MONTH', $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_changing_service_price_after_payment_does_not_change_reported_revenue(): void
    {
        $fixture = $this->successfulPayment();
        $amount = (string) $fixture['payment']->confirmed_amount;
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $before = $this->report($admin['access_token'], ['range' => 'THIS_MONTH']);
        $this->assertSame($amount, $before->json('data.summary.gross_revenue'));

        DB::table('services')->update(['original_price' => '999999.000000']);

        $after = $this->report($admin['access_token'], ['range' => 'THIS_MONTH']);
        $this->assertSame($amount, $after->json('data.summary.gross_revenue'));

        $csv = $this->get('/api/v1/admin/reports/financial/csv?range=THIS_MONTH', $this->bearer($admin['access_token']));
        $this->assertStringContainsString($amount, $csv->streamedContent());
        $this->assertStringNotContainsString('999999', $csv->streamedContent());
    }

    public function test_invalid_custom_range_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->report($admin['access_token'], ['range' => 'CUSTOM', 'from' => '2026-09-09'])
            ->assertStatus(422);
    }
}
