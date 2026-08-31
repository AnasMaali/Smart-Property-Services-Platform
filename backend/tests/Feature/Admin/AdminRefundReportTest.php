<?php

namespace Tests\Feature\Admin;

use App\Support\Payment\Gateway\RefundCreationResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\Feature\Admin\Concerns\CreatesFinancialFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Admin Reports - Refund Report
 * (App\Actions\Admin\Reports\AdminRefundReportAction). Its
 * `confirmed_count`/`confirmed_total` are read directly from
 * App\Support\Admin\AdminFinancialSummaryCalculator::compute() - the core
 * assertion this suite exists to prove is that those numbers can never
 * drift from the Financial Dashboard's own `refunds`/`refunded_count`
 * figures for the same range, and that a PENDING/FAILED refund never
 * inflates the confirmed total.
 */
class AdminRefundReportTest extends TestCase
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

        return $this->getJson('/api/v1/admin/reports/refunds'.$suffix, $this->bearer($accessToken));
    }

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->getJson('/api/v1/admin/reports/refunds')->assertStatus(401);
    }

    public function test_admin_without_payments_view_is_denied(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'payments.view')->value('id');
        DB::table('admin_role_permissions')->where('role_id', $adminRoleId)->where('permission_id', $permissionId)->delete();

        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->report($admin['access_token'], ['range' => 'THIS_MONTH'])->assertStatus(403);
    }

    public function test_confirmed_total_matches_the_financial_dashboard_refunds_figure(): void
    {
        $this->succeededRefund();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $dashboard = $this->getJson('/api/v1/admin/financial-dashboard?range=THIS_MONTH', $this->bearer($admin['access_token']));
        $report = $this->report($admin['access_token'], ['range' => 'THIS_MONTH']);

        $report->assertStatus(200);
        $this->assertSame($dashboard->json('data.summary.refunds'), $report->json('data.summary.confirmed_total'));
        $this->assertSame($dashboard->json('data.summary.bookings.refunded_count'), $report->json('data.summary.confirmed_count'));
        $this->assertSame(1, $report->json('data.summary.confirmed_count'));
    }

    public function test_pending_refund_does_not_inflate_confirmed_total(): void
    {
        config(['cancellation.timezone' => 'UTC']);

        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment(['starts_at' => now()->addDays(2)]);
        $booking = $this->bookingRowForPayment($payment);

        $this->fakeGateway()->queueNextRefund(RefundCreationResult::unknown('simulated timeout'));
        $this->postJson('/api/v1/bookings/'.UuidBinary::toString($booking->id).'/cancel', [], ['Authorization' => 'Bearer '.$customer['access_token']])
            ->assertStatus(200);

        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $response = $this->report($admin['access_token'], ['range' => 'THIS_MONTH']);

        $this->assertSame('0.000000', $response->json('data.summary.confirmed_total'));
        $this->assertSame(0, $response->json('data.summary.confirmed_count'));
        $this->assertSame(1, $response->json('data.summary.pending_count'));

        $rows = $response->json('data.refunds');
        $this->assertCount(1, $rows);
        $this->assertSame('PENDING', $rows[0]['status']);
    }

    public function test_status_filter_narrows_results(): void
    {
        $this->succeededRefund();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $succeeded = $this->report($admin['access_token'], ['range' => 'THIS_MONTH', 'status' => 'SUCCEEDED']);
        $this->assertCount(1, $succeeded->json('data.refunds'));

        $failed = $this->report($admin['access_token'], ['range' => 'THIS_MONTH', 'status' => 'FAILED']);
        $this->assertCount(0, $failed->json('data.refunds'));
    }

    public function test_csv_export_streams_matching_rows(): void
    {
        $refund = $this->succeededRefund();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->get('/api/v1/admin/reports/refunds/csv?range=THIS_MONTH', $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString((string) $refund['refund']->requested_amount, $response->streamedContent());
    }

    public function test_pdf_export_returns_a_pdf(): void
    {
        $this->succeededRefund();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->get('/api/v1/admin/reports/refunds/pdf?range=THIS_MONTH', $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
    }
}
