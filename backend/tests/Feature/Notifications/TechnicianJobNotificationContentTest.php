<?php

namespace Tests\Feature\Notifications;

use App\Support\Notifications\Gateway\LogTechnicianNotificationGateway;
use App\Support\Notifications\Gateway\NotificationDispatchData;
use App\Support\Notifications\Gateway\NotificationDispatchOutcome;
use App\Support\Notifications\TechnicianJobNotificationContent;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\Feature\Technician\Concerns\CreatesTechnicianFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B21 - pure content-assembly coverage for
 * App\Support\Notifications\TechnicianJobNotificationContent and the "log"
 * driver, independent of the Admin HTTP assign/reassign flow (see
 * tests/Feature/Admin/TechnicianNotificationTest.php for that).
 */
class TechnicianJobNotificationContentTest extends TestCase
{
    use CreatesAdminFixtures;
    use CreatesTechnicianFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_assembles_expected_operational_fields_with_asia_dubai_time(): void
    {
        $fixture = $this->bookingWithAssignableItem([
            // 10:00 UTC = 14:00 Asia/Dubai (UTC+4).
            'slot' => ['starts_at' => Carbon::parse('2026-09-15 10:00:00', 'UTC')],
        ]);
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $fields = TechnicianJobNotificationContent::forNewAssignment(
            $fixture['item']->id,
            UuidBinary::toBinary($technician['uuid'])
        );

        $this->assertSame((string) $fixture['booking']->booking_number, $fields['booking_number']);
        $this->assertStringStartsWith('1x ', $fields['service_details']);
        $this->assertSame('2:00 PM', $fields['appointment_start_time']);
        $this->assertSame('4:00 PM', $fields['appointment_end_time']);
        $this->assertSame('Tue, 15 Sep 2026', $fields['appointment_date']);
        $this->assertNotSame('', $fields['customer_name']);
        $this->assertNotSame('', $fields['visit_contact_phone']);
    }

    public function test_clean_fallback_when_floor_unit_landmark_and_notes_are_absent(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $fields = TechnicianJobNotificationContent::forNewAssignment(
            $fixture['item']->id,
            UuidBinary::toBinary($technician['uuid'])
        );

        $text = TechnicianJobNotificationContent::renderAssignmentText($fields);

        if ($fields['floor'] === '' && $fields['unit'] === '') {
            $this->assertStringNotContainsString('Floor', $text);
        }

        if ($fields['landmark'] === '') {
            $this->assertStringNotContainsString('Nearby landmark', $text);
        }

        if ($fields['location_notes'] === '') {
            $this->assertStringNotContainsString('Location notes', $text);
        }
    }

    public function test_assignment_removed_content_never_names_the_replacement_technician(): void
    {
        $fixture = $this->bookingWithAssignableItem();

        $fields = TechnicianJobNotificationContent::forAssignmentRemoved($fixture['item']->id);

        $this->assertSame(['booking_number'], array_keys($fields));

        $text = TechnicianJobNotificationContent::renderAssignmentRemovedText($fields);
        $this->assertStringContainsString('no longer assigned to you', $text);
        $this->assertStringContainsString('No action is required', $text);
    }

    public function test_template_parameters_are_never_empty_strings(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $fields = TechnicianJobNotificationContent::forNewAssignment(
            $fixture['item']->id,
            UuidBinary::toBinary($technician['uuid'])
        );

        foreach (TechnicianJobNotificationContent::assignmentTemplateParameters($fields) as $parameter) {
            $this->assertNotSame('', trim($parameter));
        }
    }

    public function test_log_driver_writes_the_rendered_text_and_never_calls_a_provider(): void
    {
        Log::spy();

        $data = new NotificationDispatchData(
            notificationUuid: (string) UuidBinary::generate(),
            recipientPhoneNumber: '+971501234567',
            templateName: 'blue_technician_assignment_v1',
            templateLanguage: 'en',
            templateParameters: ['Omar', 'BLU-TEST', 'Service', 'Today', 'Customer', 'Address'],
            renderedText: "BLUE | New Service Assignment\n\nHello Omar,",
            providerIdempotencyKey: 'blue_notify_test_key',
        );

        $result = (new LogTechnicianNotificationGateway)->send($data);

        $this->assertSame(NotificationDispatchOutcome::SUBMITTED, $result->outcome);

        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $message, array $context) => str_contains($message, 'TECHNICIAN WHATSAPP')
                && $context['message'] === $data->renderedText
                && $context['to'] === '+971501234567'
                && ! isset($context['access_token'])
        );
    }
}
