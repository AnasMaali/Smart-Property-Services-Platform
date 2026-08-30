<?php

namespace Tests\Feature\Booking;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B20 - App\Actions\Booking\PreviewBookingCancellationAction,
 * exercised through both the customer and Admin routes it backs verbatim
 * (never a separate Admin calculator - see that Action's docblock).
 */
class CancellationPreviewTest extends TestCase
{
    use CreatesAdminFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
        config([
            'cancellation.timezone' => 'UTC',
            'cancellation.before_appointment_day_percentage' => 100,
            'cancellation.appointment_day_percentage' => 75,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function previewCustomer(string $accessToken, object $booking)
    {
        return $this->getJson(
            '/api/v1/bookings/'.UuidBinary::toString($booking->id).'/cancellation-preview',
            ['Authorization' => 'Bearer '.$accessToken]
        );
    }

    private function previewAdmin(string $accessToken, object $booking)
    {
        return $this->getJson(
            '/api/v1/admin/bookings/'.UuidBinary::toString($booking->id).'/cancellation-preview',
            $this->bearer($accessToken)
        );
    }

    public function test_preview_before_appointment_day_shows_100_percent_and_is_cancellable(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);
        $booking = $this->bookingRowForPayment($payment);

        Carbon::setTestNow('2026-09-14 20:00:00');

        $response = $this->previewCustomer($customer['access_token'], $booking);

        $response->assertStatus(200)
            ->assertJsonPath('data.preview.cancellable', true)
            ->assertJsonPath('data.preview.refund.percentage', 100)
            ->assertJsonPath('data.preview.refund.execution', 'AUTOMATIC');

        $paidAmount = (string) ($payment->confirmed_amount ?? $payment->requested_amount);
        $this->assertSame($paidAmount, $response->json('data.preview.paid_amount'));
    }

    // -----------------------------------------------------------------
    // FIX PHASE item 2 - the preview contract must carry everything a
    // confirmation screen needs in one server-authoritative response.
    // -----------------------------------------------------------------

    public function test_preview_response_carries_everything_a_confirmation_screen_needs(): void
    {
        $startsAt = Carbon::parse('2026-09-15 10:00:00');
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => $startsAt,
        ]);
        $booking = $this->bookingRowForPayment($payment);

        Carbon::setTestNow('2026-09-15 05:00:00');

        $response = $this->previewCustomer($customer['access_token'], $booking);

        $response->assertStatus(200)
            ->assertJsonPath('data.preview.cancellable', true)
            ->assertJsonPath('data.preview.reason_code', 'APPOINTMENT_DAY_BEFORE_START')
            ->assertJsonPath('data.preview.appointment.starts_at', $startsAt->toIso8601String())
            ->assertJsonPath('data.preview.refund.percentage', 75)
            ->assertJsonPath('data.preview.refund.execution', 'AUTOMATIC')
            ->assertJsonPath('data.preview.refund.method', 'ORIGINAL_PAYMENT_METHOD');

        $data = $response->json('data.preview');
        $this->assertSame((string) $payment->confirmed_amount, $data['paid_amount']);
        $this->assertNotNull($data['currency']);
        $this->assertNotNull($data['refund']['amount']);

        // Never Stripe internals on the customer-facing preview.
        $this->assertStringNotContainsString('stripe', strtolower($response->getContent()));
    }

    public function test_admin_preview_also_carries_appointment_and_destination_fields(): void
    {
        ['payment' => $payment] = $this->successfulPayment(['starts_at' => now()->addDays(2)]);
        $booking = $this->bookingRowForPayment($payment);
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->previewAdmin($admin['access_token'], $booking);

        $response->assertStatus(200)
            ->assertJsonPath('data.preview.refund.method', 'ORIGINAL_PAYMENT_METHOD');
        $this->assertNotNull($response->json('data.preview.appointment.starts_at'));
    }

    public function test_preview_at_or_after_appointment_start_is_not_cancellable(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 09:00:00'),
        ]);
        $booking = $this->bookingRowForPayment($payment);

        Carbon::setTestNow('2026-09-15 09:00:00');

        $response = $this->previewCustomer($customer['access_token'], $booking);

        $response->assertStatus(200)
            ->assertJsonPath('data.preview.cancellable', false)
            ->assertJsonPath('data.preview.reason_code', 'APPOINTMENT_ALREADY_STARTED')
            ->assertJsonPath('data.preview.refund', null);
    }

    public function test_preview_never_mutates_anything(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => now()->addDays(2),
        ]);
        $booking = $this->bookingRowForPayment($payment);

        $this->previewCustomer($customer['access_token'], $booking)->assertStatus(200);

        $fresh = $this->bookingRow(UuidBinary::toString($booking->id));
        $this->assertSame('PAID', $this->bookingStatus($fresh));
        $this->assertNull($fresh->cancellation_refund_percentage);
    }

    public function test_foreign_customer_cannot_preview_another_customers_booking(): void
    {
        ['payment' => $payment] = $this->successfulPayment(['starts_at' => now()->addDays(2)]);
        $booking = $this->bookingRowForPayment($payment);
        $stranger = $this->createAuthenticatedCartCustomer();

        $this->previewCustomer($stranger['access_token'], $booking)->assertStatus(404);
    }

    public function test_admin_preview_returns_the_same_policy_result_as_customer_preview(): void
    {
        // A near-future (same real calendar day) appointment, with no
        // Carbon::setTestNow() at all - both the Admin JWT (validated
        // against the real system clock by firebase/php-jwt, independent
        // of Carbon) and AdminSessionPolicy's Carbon-based idle timeout
        // stay trivially satisfied, and the appointment is still "today,
        // before it starts" -> the same 75% branch a distant frozen date
        // would exercise, without either concern.
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::now()->addMinutes(10),
        ]);
        $booking = $this->bookingRowForPayment($payment);
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $customerPreview = $this->previewCustomer($customer['access_token'], $booking);
        $adminPreview = $this->previewAdmin($admin['access_token'], $booking);

        $adminPreview->assertStatus(200);
        $this->assertSame($customerPreview->json('data.preview'), $adminPreview->json('data.preview'));
        $this->assertSame(75, $adminPreview->json('data.preview.refund.percentage'));
    }

    public function test_admin_preview_requires_bookings_cancel_capability(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'bookings.cancel')->value('id');
        DB::table('admin_role_permissions')->where('role_id', $adminRoleId)->where('permission_id', $permissionId)->delete();

        $admin = $this->createAndLoginAdmin(['ADMIN']);
        ['payment' => $payment] = $this->successfulPayment(['starts_at' => now()->addDays(2)]);
        $booking = $this->bookingRowForPayment($payment);

        $this->previewAdmin($admin['access_token'], $booking)->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // FIX PHASE item 7 - confirmed_amount is the ONLY financial source
    // of truth; a Booking whose payment somehow lacks it must never be
    // previewed for an automated refund by guessing from
    // requested_amount.
    // -----------------------------------------------------------------

    public function test_preview_rejects_when_confirmed_amount_is_missing(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => now()->addDays(2),
        ]);
        $booking = $this->bookingRowForPayment($payment);

        // Simulates a reconciliation-failure payment: a Booking already
        // exists for it, but its confirmed-successful disposition data has
        // gone missing. chk_payment_attempts_successful_at requires
        // successful_at to be null whenever confirmed_amount is null, so
        // both are cleared together to keep this an otherwise-valid row.
        DB::table('payment_attempts')->where('id', $payment->id)->update([
            'confirmed_amount' => null,
            'successful_at' => null,
        ]);

        $response = $this->previewCustomer($customer['access_token'], $booking);

        $response->assertStatus(409);
        $this->assertStringContainsString('confirmed amount', strtolower($response->json('message')));
    }

    private function bookingStatus(object $booking): string
    {
        $statusId = DB::table('bookings')->where('id', $booking->id)->value('status_id');

        return (string) DB::table('booking_statuses')->where('id', $statusId)->value('code');
    }
}
