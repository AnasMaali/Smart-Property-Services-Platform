<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use App\Support\WhatsApp\WhatsAppLinkBuilder;
use App\Support\WhatsApp\WhatsAppMessagePresenter;
use App\Support\WhatsApp\WhatsAppPhoneNormalizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\Feature\Technician\Concerns\CreatesTechnicianFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Simple WhatsApp Handoff Links - no Meta API, no delivery ledger.
 * Exercises the wa.me handoff data App\Support\Admin\AdminBookingPresenter
 * now attaches to `active_assignment`/`assignment_history`/
 * `customer_whatsapp` on the existing GET /v1/admin/bookings/{booking}
 * response - never a new endpoint, never a new table.
 */
class SimpleWhatsAppHandoffTest extends TestCase
{
    use CreatesAdminFixtures;
    use CreatesTechnicianFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    private function assignUrl(object $item): string
    {
        return '/api/v1/admin/booking-items/'.UuidBinary::toString($item->id).'/assign-technician';
    }

    private function reassignUrl(object $item): string
    {
        return '/api/v1/admin/booking-items/'.UuidBinary::toString($item->id).'/reassign-technician';
    }

    private function fetchBooking(string $bookingUuid, array $headers): array
    {
        return $this->getJson('/api/v1/admin/bookings/'.$bookingUuid, $headers)->json('data.booking');
    }

    // -----------------------------------------------------------------
    // 1. Active assignment exposes Technician WhatsApp action data.
    // -----------------------------------------------------------------

    public function test_active_assignment_exposes_technician_whatsapp_handoff(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        $booking = $this->fetchBooking(UuidBinary::toString($fixture['booking']->id), $this->bearer($admin['access_token']));
        $whatsapp = $booking['items'][0]['active_assignment']['whatsapp'];

        $this->assertNotNull($whatsapp);
        $this->assertStringStartsWith('https://wa.me/', $whatsapp['url']);
        $this->assertStringContainsString('New Service Assignment', $whatsapp['message']);
    }

    // -----------------------------------------------------------------
    // 2. Technician message contains booking number/service/appointment/
    //    customer/visit phone/location.
    // -----------------------------------------------------------------

    public function test_technician_message_contains_required_fields(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        $booking = $this->fetchBooking(UuidBinary::toString($fixture['booking']->id), $this->bearer($admin['access_token']));
        $message = $booking['items'][0]['active_assignment']['whatsapp']['message'];

        $this->assertStringContainsString($booking['booking_number'], $message);
        $this->assertStringContainsString($booking['items'][0]['service']['name'], $message);
        $this->assertStringContainsString($booking['customer']['full_name'], $message);
        $this->assertStringContainsString($booking['location']['visit_contact_phone'], $message);
        $this->assertStringContainsString($booking['location']['street_name'], $message);
        $this->assertStringContainsString($booking['location']['area_name'], $message);
        $this->assertStringContainsString('Please arrive during the scheduled appointment window.', $message);
    }

    // -----------------------------------------------------------------
    // 3. Technician message excludes financial/internal data.
    // -----------------------------------------------------------------

    public function test_technician_message_excludes_financial_and_internal_data(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $booking = $this->fetchBooking(UuidBinary::toString($fixture['booking']->id), $this->bearer($admin['access_token']));
        $message = strtolower($booking['items'][0]['active_assignment']['whatsapp']['message']);

        foreach (['stripe', 'payment_intent', 'refund', 'aed', (string) $booking['total']] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $message);
        }

        // Never a raw internal UUID either.
        $this->assertStringNotContainsString($response->json('data.assignment.uuid'), $message);
        $this->assertStringNotContainsString($booking['uuid'], $message);
    }

    // -----------------------------------------------------------------
    // 4. Customer message contains booking/service/technician/appointment/
    //    historical paid amount/AED.
    // -----------------------------------------------------------------

    public function test_customer_message_contains_required_fields_and_historical_paid_amount(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        $payment = DB::table('payment_attempts')->where('id', $fixture['booking']->payment_attempt_id)->first(['confirmed_amount', 'requested_amount']);
        $expectedAmount = (string) ($payment->confirmed_amount ?? $payment->requested_amount);

        $booking = $this->fetchBooking(UuidBinary::toString($fixture['booking']->id), $this->bearer($admin['access_token']));
        $whatsapp = $booking['items'][0]['customer_whatsapp'];

        $this->assertNotNull($whatsapp);
        $this->assertStringContainsString($booking['booking_number'], $whatsapp['message']);
        $this->assertStringContainsString($booking['items'][0]['service']['name'], $whatsapp['message']);
        $technicianName = DB::table('technicians')->where('id', UuidBinary::toBinary($technician['uuid']))->value('full_name');
        $this->assertStringContainsString($technicianName, $whatsapp['message']);
        $this->assertStringContainsString("{$expectedAmount} AED", $whatsapp['message']);
        $this->assertStringContainsString('Your technician has been assigned.', $whatsapp['message']);
    }

    // -----------------------------------------------------------------
    // 5. Changing Service price after Booking does NOT change the
    //    Customer WhatsApp paid amount.
    // -----------------------------------------------------------------

    public function test_customer_message_paid_amount_ignores_later_service_price_change(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $payment = DB::table('payment_attempts')->where('id', $fixture['booking']->payment_attempt_id)->first(['confirmed_amount', 'requested_amount']);
        $expectedAmount = (string) ($payment->confirmed_amount ?? $payment->requested_amount);

        DB::table('pricing_rules')
            ->where('pricing_scheme_version_id', $fixture['item']->pricing_scheme_version_id)
            ->update(['effect_amount' => '999999.000000']);

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        $booking = $this->fetchBooking(UuidBinary::toString($fixture['booking']->id), $this->bearer($admin['access_token']));
        $message = $booking['items'][0]['customer_whatsapp']['message'];

        $this->assertStringContainsString("{$expectedAmount} AED", $message);
        $this->assertStringNotContainsString('999999', $message);
    }

    // -----------------------------------------------------------------
    // 6. Human-facing appointment time uses Asia/Dubai.
    // -----------------------------------------------------------------

    public function test_messages_use_asia_dubai_for_human_facing_appointment_time(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        // 10:00 UTC = 14:00 Asia/Dubai (UTC+4).
        $fixture = $this->bookingWithAssignableItem([
            'slot' => ['starts_at' => Carbon::parse('2026-09-15 10:00:00', 'UTC')],
        ]);
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        $booking = $this->fetchBooking(UuidBinary::toString($fixture['booking']->id), $this->bearer($admin['access_token']));

        $this->assertStringContainsString('2:00 PM', $booking['items'][0]['active_assignment']['whatsapp']['message']);
        $this->assertStringContainsString('2:00 PM', $booking['items'][0]['customer_whatsapp']['message']);
    }

    // -----------------------------------------------------------------
    // 7. Valid E.164 phone produces correct wa.me number.
    // -----------------------------------------------------------------

    public function test_valid_e164_phone_produces_correct_wa_me_number(): void
    {
        $this->assertSame('971501234567', WhatsAppPhoneNormalizer::toWaMeDigits('+971501234567'));

        $link = WhatsAppLinkBuilder::build('+971501234567', "line one\nline two");
        $this->assertNotNull($link);
        $this->assertSame('https://wa.me/971501234567?text=line%20one%0Aline%20two', $link['url']);
    }

    // -----------------------------------------------------------------
    // 8. Invalid/missing phone produces no unsafe WhatsApp URL.
    // -----------------------------------------------------------------

    public function test_invalid_or_missing_phone_produces_no_whatsapp_link(): void
    {
        $this->assertNull(WhatsAppPhoneNormalizer::toWaMeDigits(null));
        $this->assertNull(WhatsAppPhoneNormalizer::toWaMeDigits('0501234567'));
        $this->assertNull(WhatsAppPhoneNormalizer::toWaMeDigits('not-a-phone'));

        $this->assertNull(WhatsAppLinkBuilder::build(null, 'hello'));
        $this->assertNull(WhatsAppLinkBuilder::build('0501234567', 'hello'));

        // End-to-end: a Technician with an invalid stored phone number
        // never gets a whatsapp handoff, and Booking rendering never
        // crashes.
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id'], [
            'phone_number' => '0501234567',
        ]);

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));
        $response->assertStatus(201);

        $booking = $this->fetchBooking(UuidBinary::toString($fixture['booking']->id), $this->bearer($admin['access_token']));
        $this->assertNull($booking['items'][0]['active_assignment']['whatsapp']);
    }

    // -----------------------------------------------------------------
    // 9. URL encoding preserves line breaks and Unicode safely.
    // -----------------------------------------------------------------

    public function test_url_encoding_preserves_line_breaks_and_unicode(): void
    {
        $link = WhatsAppLinkBuilder::build('+971501234567', "Hello\nمرحبا\n<script>");

        $this->assertNotNull($link);
        $this->assertStringContainsString('%0A', $link['url']);
        $this->assertStringNotContainsString('<script>', $link['url']);
        $this->assertStringNotContainsString(' ', $link['url']);
        $this->assertStringNotContainsString("\n", $link['url']);
    }

    // -----------------------------------------------------------------
    // 10. Reassigned previous Technician removal message contains
    //     Booking number but does NOT reveal the new Technician.
    // -----------------------------------------------------------------

    public function test_previous_technician_removal_message_never_reveals_new_technician(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $original = $this->createEligibleTechnician($fixture['specialization_id']);
        $replacement = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $original['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        $this->postJson($this->reassignUrl($fixture['item']), [
            'technician_uuid' => $replacement['uuid'],
            'release_reason' => 'Original technician unavailable.',
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $booking = $this->fetchBooking(UuidBinary::toString($fixture['booking']->id), $this->bearer($admin['access_token']));
        $history = $booking['items'][0]['assignment_history'];

        $releasedEntry = collect($history)->first(fn ($entry) => $entry['released_at'] !== null);
        $this->assertNotNull($releasedEntry);
        $this->assertNotNull($releasedEntry['whatsapp']);

        $removalMessage = $releasedEntry['whatsapp']['message'];
        $newTechnicianName = DB::table('technicians')->where('id', UuidBinary::toBinary($replacement['uuid']))->value('full_name');

        $this->assertStringContainsString($booking['booking_number'], $removalMessage);
        $this->assertStringContainsString('No action is required.', $removalMessage);
        $this->assertStringNotContainsString($newTechnicianName, $removalMessage);
        $this->assertStringNotContainsString('Original technician unavailable.', $removalMessage);

        // The new technician's own message is the normal NEW ASSIGNMENT one.
        $activeMessage = $booking['items'][0]['active_assignment']['whatsapp']['message'];
        $this->assertStringContainsString('New Service Assignment', $activeMessage);
        $this->assertStringContainsString($newTechnicianName, $activeMessage);

        // The customer message reads as an update, not a first assignment.
        $customerMessage = $booking['items'][0]['customer_whatsapp']['message'];
        $this->assertStringContainsString('The technician assigned to your booking has been updated.', $customerMessage);
        $this->assertStringContainsString($newTechnicianName, $customerMessage);
    }

    // -----------------------------------------------------------------
    // 11. Existing assignment/reassignment behavior remains unchanged
    //     (a WhatsApp-link failure/absence can never affect it).
    // -----------------------------------------------------------------

    public function test_assignment_behavior_is_unaffected_by_whatsapp_handoff(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id'], [
            'phone_number' => '0501234567', // invalid E.164 - no whatsapp link possible
        ]);

        $response = $this->postJson($this->assignUrl($fixture['item']), [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(201);
        $this->assertSame(
            'ASSIGNED',
            DB::table('booking_item_statuses')->where(
                'id',
                DB::table('booking_items')->where('id', $fixture['item']->id)->value('status_id')
            )->value('code')
        );
        $this->assertSame(1, DB::table('technician_assignments')->where('booking_item_id', $fixture['item']->id)->whereNull('released_at')->count());
    }

    // -----------------------------------------------------------------
    // Presenter-level unit coverage for the message templates directly.
    // -----------------------------------------------------------------

    public function test_technician_removed_message_shape(): void
    {
        $message = WhatsAppMessagePresenter::technicianRemoved('BLU-0001');

        $this->assertStringContainsString('BLU-0001', $message);
        $this->assertStringContainsString('is no longer assigned to you', $message);
        $this->assertStringContainsString('No action is required.', $message);
    }
}
