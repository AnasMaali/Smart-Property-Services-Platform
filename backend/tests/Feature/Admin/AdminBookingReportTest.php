<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\Feature\Admin\Concerns\CreatesFinancialFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Admin Reports - Booking Report
 * (App\Actions\Admin\Reports\AdminBookingReportAction). `total` is always a
 * frozen `booking_items.line_total_amount` snapshot - never a Service's
 * current price - see test_changing_service_price_after_booking_does_not_change_reported_total().
 */
class AdminBookingReportTest extends TestCase
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

        return $this->getJson('/api/v1/admin/reports/bookings'.$suffix, $this->bearer($accessToken));
    }

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->getJson('/api/v1/admin/reports/bookings')->assertStatus(401);
    }

    public function test_admin_without_bookings_view_is_denied(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'bookings.view')->value('id');
        DB::table('admin_role_permissions')->where('role_id', $adminRoleId)->where('permission_id', $permissionId)->delete();

        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->report($admin['access_token'], ['range' => 'THIS_MONTH'])->assertStatus(403);
    }

    public function test_screen_lists_a_paid_booking_with_the_frozen_total(): void
    {
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);
        $amount = (string) $fixture['payment']->confirmed_amount;
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->report($admin['access_token'], ['range' => 'THIS_MONTH']);

        $response->assertStatus(200);
        $rows = $response->json('data.bookings');
        $this->assertCount(1, $rows);
        $this->assertSame($booking->booking_number, $rows[0]['booking_number']);
        $this->assertSame($amount, $rows[0]['total']);
        $this->assertSame('PAID', $rows[0]['status']);
        $this->assertSame(1, $response->json('data.summary.total_bookings'));
    }

    public function test_status_filter_narrows_results(): void
    {
        $this->successfulPayment();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $matching = $this->report($admin['access_token'], ['range' => 'THIS_MONTH', 'status' => 'PAID']);
        $this->assertCount(1, $matching->json('data.bookings'));

        $nonMatching = $this->report($admin['access_token'], ['range' => 'THIS_MONTH', 'status' => 'CANCELLED']);
        $this->assertCount(0, $nonMatching->json('data.bookings'));
    }

    public function test_payment_method_filter_narrows_results(): void
    {
        $this->successfulPayment();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $matching = $this->report($admin['access_token'], ['range' => 'THIS_MONTH', 'payment_method' => 'CARD']);
        $this->assertCount(1, $matching->json('data.bookings'));

        $nonMatching = $this->report($admin['access_token'], ['range' => 'THIS_MONTH', 'payment_method' => 'APPLE_PAY']);
        $this->assertCount(0, $nonMatching->json('data.bookings'));
    }

    public function test_summary_counts_completed_and_cancelled_correctly(): void
    {
        $refund = $this->succeededRefund();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->report($admin['access_token'], ['range' => 'THIS_MONTH']);

        $this->assertSame(1, $response->json('data.summary.total_bookings'));
        $this->assertSame(1, $response->json('data.summary.cancelled'));
        $this->assertSame(0, $response->json('data.summary.completed'));
    }

    public function test_csv_export_streams_all_matching_rows(): void
    {
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->get('/api/v1/admin/reports/bookings/csv?range=THIS_MONTH', $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $body = $response->streamedContent();
        $this->assertStringContainsString($booking->booking_number, $body);
        $this->assertStringContainsString('Booking Number', $body);
    }

    /**
     * CSV formula injection protection - a customer full_name starting
     * with `=` must be escaped with a leading `'` so no spreadsheet
     * application ever evaluates it as a formula.
     */
    public function test_csv_export_escapes_formula_injection_in_customer_name(): void
    {
        $fixture = $this->successfulPayment();
        DB::table('user_profiles')
            ->where('user_id', UuidBinary::toBinary($fixture['customer']['user_uuid']))
            ->update(['full_name' => '=SUM(1+1)']);

        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $response = $this->get('/api/v1/admin/reports/bookings/csv?range=THIS_MONTH', $this->bearer($admin['access_token']));

        $body = $response->streamedContent();
        $this->assertStringNotContainsString(',=SUM(1+1),', $body);
        $this->assertStringContainsString("'=SUM(1+1)", $body);
    }

    public function test_pdf_export_returns_a_pdf(): void
    {
        $this->successfulPayment();
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->get('/api/v1/admin/reports/bookings/pdf?range=THIS_MONTH', $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_changing_service_price_after_booking_does_not_change_reported_total(): void
    {
        $fixture = $this->successfulPayment();
        $amount = (string) $fixture['payment']->confirmed_amount;
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        DB::table('services')->update(['original_price' => '999999.000000']);

        $response = $this->report($admin['access_token'], ['range' => 'THIS_MONTH']);
        $this->assertSame($amount, $response->json('data.bookings.0.total'));

        $csv = $this->get('/api/v1/admin/reports/bookings/csv?range=THIS_MONTH', $this->bearer($admin['access_token']));
        $this->assertStringContainsString($amount, $csv->streamedContent());
        $this->assertStringNotContainsString('999999', $csv->streamedContent());
    }
}
