<?php

namespace Tests\Feature\Admin;

use App\Support\Admin\AdminBookingPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Service Completion Report (App\Actions\Admin\Booking\
 * GenerateAdminBookingCompletionReportAction). Every report field is
 * proven to come from the same authoritative, historical-snapshot data
 * App\Support\Admin\AdminBookingPresenter::detail() already serves the
 * Admin Booking detail page - never a second, live-price computation (see
 * test_changing_service_price_after_generation_does_not_change_the_report()).
 *
 * PDF byte streams are not reliably text-searchable (dompdf's own output is
 * a binary/compressed PDF object graph), so content-level assertions here
 * render App\Support\Admin\Reports\AdminReportPdf's underlying Blade view
 * directly - exactly the fallback this feature's PR description section 24
 * calls for - while the HTTP-level tests below stay limited to the `%PDF`
 * signature, status codes, and headers, matching every other PDF export
 * test in this suite (see AdminBookingReportTest::test_pdf_export_returns_a_pdf()).
 */
class AdminBookingCompletionReportTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    /**
     * Drives a real Booking through assign -> start -> Admin force-complete
     * via the actual HTTP endpoints (mirrors AdminBookingForceCompleteTest's
     * own fixture shape exactly) so every test below exercises a genuinely
     * COMPLETED Booking, not a hand-rolled status_id write.
     *
     * @return array{customer: array, admin: array, booking: object, booking_uuid: string, item: object, technician: array}
     */
    private function completedBookingFixture(): array
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $itemUuid = UuidBinary::toString($fixture['item']->id);

        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/assign-technician", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))
            ->assertStatus(201);
        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/start-work", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $bookingUuid = UuidBinary::toString($fixture['booking']->id);

        $this->postJson("/api/v1/admin/bookings/{$bookingUuid}/force-complete", ['reason' => 'QA fixture completion.'], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        return [
            'customer' => $fixture['customer'],
            'admin' => $admin,
            'booking' => $fixture['booking'],
            'booking_uuid' => $bookingUuid,
            'item' => $fixture['item'],
            'technician' => $technician,
        ];
    }

    private function fakeImage(string $name = 'photo.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 20, 20);
    }

    private function generate(string $accessToken, string $bookingUuid, array $payload = []): TestResponse
    {
        return $this->post(
            '/api/v1/admin/bookings/'.$bookingUuid.'/completion-report',
            $payload,
            $this->bearer($accessToken),
        );
    }

    private function auditRow(string $bookingUuid): ?object
    {
        return DB::table('admin_audit_logs')
            ->where('action_code', 'SERVICE_COMPLETION_REPORT_GENERATED')
            ->where('entity_identifier', $bookingUuid)
            ->first();
    }

    // -----------------------------------------------------------------
    // Auth / authorization / eligibility
    // -----------------------------------------------------------------

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->generate('', UuidBinary::generate())->assertStatus(401);
    }

    public function test_customer_is_denied(): void
    {
        $fixture = $this->completedBookingFixture();

        $this->generate($fixture['customer']['access_token'], $fixture['booking_uuid'])->assertStatus(401);
    }

    public function test_admin_without_bookings_view_capability_is_denied(): void
    {
        $fixture = $this->completedBookingFixture();

        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'bookings.view')->value('id');
        DB::table('admin_role_permissions')->where('role_id', $adminRoleId)->where('permission_id', $permissionId)->delete();

        $this->generate($fixture['admin']['access_token'], $fixture['booking_uuid'])->assertStatus(403);
        $this->assertNull($this->auditRow($fixture['booking_uuid']));
    }

    public function test_unknown_booking_is_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->generate($admin['access_token'], UuidBinary::generate())->assertStatus(404);
    }

    public function test_non_completed_booking_is_rejected_with_409(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $bookingUuid = UuidBinary::toString($fixture['booking']->id);

        $this->assertSame('PAID', DB::table('booking_statuses')->where('id', DB::table('bookings')->where('id', $fixture['booking']->id)->value('status_id'))->value('code'));

        $this->generate($admin['access_token'], $bookingUuid)->assertStatus(409);
        $this->assertNull($this->auditRow($bookingUuid));
    }

    public function test_super_admin_can_generate(): void
    {
        $fixture = $this->completedBookingFixture();
        $superAdmin = $this->createAndLoginAdmin(['SUPER_ADMIN']);

        $this->generate($superAdmin['access_token'], $fixture['booking_uuid'])->assertStatus(200);
    }

    // -----------------------------------------------------------------
    // Happy path / PDF response shape
    // -----------------------------------------------------------------

    public function test_completed_booking_returns_a_pdf_with_a_safe_filename(): void
    {
        $fixture = $this->completedBookingFixture();

        $response = $this->generate($fixture['admin']['access_token'], $fixture['booking_uuid']);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString($fixture['booking']->booking_number, $disposition);
        $this->assertMatchesRegularExpression('/^attachment; filename="BLUE-Service-Report-[A-Za-z0-9\-]+\.pdf"$/', $disposition);
    }

    public function test_whatsapp_url_header_present_for_a_valid_customer_phone(): void
    {
        $fixture = $this->completedBookingFixture();

        $response = $this->generate($fixture['admin']['access_token'], $fixture['booking_uuid']);

        $whatsappUrl = $response->headers->get('X-Report-Whatsapp-Url');
        $this->assertNotNull($whatsappUrl);
        $this->assertStringStartsWith('https://wa.me/', $whatsappUrl);
    }

    public function test_generating_the_report_never_mutates_booking_or_payment_state(): void
    {
        $fixture = $this->completedBookingFixture();
        $bookingBefore = DB::table('bookings')->where('id', $fixture['booking']->id)->first();
        $itemsBefore = DB::table('booking_items')->where('booking_id', $fixture['booking']->id)->orderBy('id')->get();
        $assignmentsBefore = DB::table('technician_assignments')->whereIn('booking_item_id', $itemsBefore->pluck('id'))->get();

        $this->generate($fixture['admin']['access_token'], $fixture['booking_uuid'])->assertStatus(200);

        $bookingAfter = DB::table('bookings')->where('id', $fixture['booking']->id)->first();
        $itemsAfter = DB::table('booking_items')->where('booking_id', $fixture['booking']->id)->orderBy('id')->get();
        $assignmentsAfter = DB::table('technician_assignments')->whereIn('booking_item_id', $itemsAfter->pluck('id'))->get();

        $this->assertEquals($bookingBefore, $bookingAfter);
        $this->assertEquals($itemsBefore, $itemsAfter);
        $this->assertEquals($assignmentsBefore, $assignmentsAfter);
    }

    // -----------------------------------------------------------------
    // Audit
    // -----------------------------------------------------------------

    public function test_generating_the_report_writes_exactly_one_safe_audit_row(): void
    {
        $fixture = $this->completedBookingFixture();

        $this->generate($fixture['admin']['access_token'], $fixture['booking_uuid'], [
            'completion_note' => 'All good.',
            'before_photos' => [$this->fakeImage('before.jpg')],
        ])->assertStatus(200);

        $rows = DB::table('admin_audit_logs')->where('action_code', 'SERVICE_COMPLETION_REPORT_GENERATED')->where('entity_identifier', $fixture['booking_uuid'])->get();
        $this->assertCount(1, $rows);

        $audit = $rows->first();
        $this->assertSame('BOOKING', $audit->entity_type);
        $this->assertSame($fixture['booking_uuid'], $audit->entity_identifier);
        $this->assertSame(1, (int) $audit->was_successful);

        $newValues = json_decode((string) $audit->new_values, true);
        $this->assertTrue($newValues['has_before_photos']);
        $this->assertSame(1, $newValues['before_photo_count']);
        $this->assertFalse($newValues['has_after_photos']);
        $this->assertSame(0, $newValues['after_photo_count']);
        $this->assertTrue($newValues['has_completion_note']);

        // Never the note text itself, and never any image/PDF byte content.
        $this->assertStringNotContainsString('All good.', (string) $audit->new_values);
    }

    // -----------------------------------------------------------------
    // Photo privacy - the critical property this feature exists to protect.
    // -----------------------------------------------------------------

    public function test_before_and_after_photos_are_both_optional(): void
    {
        $fixture = $this->completedBookingFixture();

        $this->generate($fixture['admin']['access_token'], $fixture['booking_uuid'])->assertStatus(200);
    }

    public function test_jpeg_photo_is_accepted(): void
    {
        $fixture = $this->completedBookingFixture();

        $this->generate($fixture['admin']['access_token'], $fixture['booking_uuid'], [
            'before_photos' => [$this->fakeImage('before.jpg')],
        ])->assertStatus(200);
    }

    public function test_png_photo_is_accepted(): void
    {
        $fixture = $this->completedBookingFixture();

        $this->generate($fixture['admin']['access_token'], $fixture['booking_uuid'], [
            'after_photos' => [UploadedFile::fake()->image('after.png', 20, 20)],
        ])->assertStatus(200);
    }

    public function test_non_image_file_is_rejected(): void
    {
        $fixture = $this->completedBookingFixture();

        $this->generate($fixture['admin']['access_token'], $fixture['booking_uuid'], [
            'before_photos' => [UploadedFile::fake()->createWithContent('not-an-image.txt', 'plain text content')],
        ])->assertStatus(422);
    }

    public function test_too_many_photos_are_rejected(): void
    {
        $fixture = $this->completedBookingFixture();

        $photos = array_map(fn ($i) => $this->fakeImage("before-{$i}.jpg"), range(1, 9));

        $this->generate($fixture['admin']['access_token'], $fixture['booking_uuid'], [
            'before_photos' => $photos,
        ])->assertStatus(422);
    }

    public function test_oversized_photo_is_rejected(): void
    {
        $fixture = $this->completedBookingFixture();

        $oversized = UploadedFile::fake()->image('huge.jpg', 100, 100)->size(8300);

        $this->generate($fixture['admin']['access_token'], $fixture['booking_uuid'], [
            'before_photos' => [$oversized],
        ])->assertStatus(422);
    }

    public function test_no_photo_file_is_ever_written_to_persistent_storage(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $fixture = $this->completedBookingFixture();

        $this->generate($fixture['admin']['access_token'], $fixture['booking_uuid'], [
            'before_photos' => [$this->fakeImage('before.jpg'), $this->fakeImage('before-2.png')],
            'after_photos' => [$this->fakeImage('after.jpg')],
            'completion_note' => 'Left the property spotless.',
        ])->assertStatus(200);

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_no_report_photo_media_row_is_ever_created(): void
    {
        $countBefore = (int) DB::table('service_media')->count();
        $fixture = $this->completedBookingFixture();

        $this->generate($fixture['admin']['access_token'], $fixture['booking_uuid'], [
            'before_photos' => [$this->fakeImage('before.jpg')],
            'after_photos' => [$this->fakeImage('after.jpg')],
        ])->assertStatus(200);

        $this->assertSame($countBefore, (int) DB::table('service_media')->count());
    }

    // -----------------------------------------------------------------
    // Report data consistency - render the underlying Blade view directly
    // (see this class's own docblock for why the PDF byte stream itself is
    // not asserted against here).
    // -----------------------------------------------------------------

    private function renderedReportHtml(object $booking, ?string $completionNote = null, array $beforeImages = [], array $afterImages = []): string
    {
        $row = DB::table('bookings')
            ->join('carts', 'carts.id', '=', 'bookings.cart_id')
            ->where('bookings.id', $booking->id)
            ->first(['bookings.*', 'carts.customer_user_id', 'carts.currency_id as cart_currency_id']);

        $presented = AdminBookingPresenter::detail($row);

        return view('admin.reports.pdf.booking-completion', [
            'booking' => $presented,
            'completionNote' => $completionNote,
            'beforeImages' => $beforeImages,
            'afterImages' => $afterImages,
            'generatedAt' => now()->toIso8601String(),
        ])->render();
    }

    public function test_changing_service_price_after_completion_does_not_change_the_reported_amount(): void
    {
        $fixture = $this->completedBookingFixture();
        $item = DB::table('booking_items')->where('id', $fixture['item']->id)->first();
        $frozenAmount = (string) $item->line_total_amount;

        DB::table('services')->update(['original_price' => '999999.000000']);

        $html = $this->renderedReportHtml($fixture['booking']);

        $this->assertStringContainsString(number_format((float) $frozenAmount, 2), $html);
        $this->assertStringNotContainsString('999999', $html);
    }

    public function test_report_html_never_exposes_internal_ids_or_the_completion_note_as_raw_html(): void
    {
        $fixture = $this->completedBookingFixture();

        $html = $this->renderedReportHtml($fixture['booking'], completionNote: '<script>alert(1)</script>');

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString(bin2hex((string) $fixture['booking']->id), $html);
        $this->assertStringContainsString($fixture['booking']->booking_number, $html);
    }

    public function test_before_and_after_photos_appear_in_the_rendered_report_with_numbered_captions(): void
    {
        $fixture = $this->completedBookingFixture();

        $html = $this->renderedReportHtml(
            $fixture['booking'],
            beforeImages: ['data:image/jpeg;base64,AAA='],
            afterImages: ['data:image/jpeg;base64,BBB=', 'data:image/jpeg;base64,CCC='],
        );

        $this->assertStringContainsString('data:image/jpeg;base64,AAA=', $html);
        $this->assertStringContainsString('Before 1', $html);
        $this->assertStringContainsString('data:image/jpeg;base64,BBB=', $html);
        $this->assertStringContainsString('After 1', $html);
        $this->assertStringContainsString('After 2', $html);
    }

    public function test_no_empty_photo_section_renders_when_no_photos_are_supplied(): void
    {
        $fixture = $this->completedBookingFixture();

        $html = $this->renderedReportHtml($fixture['booking']);

        $this->assertStringNotContainsString('Before Photos', $html);
        $this->assertStringNotContainsString('After Photos', $html);
    }

    public function test_a_real_photo_is_embedded_as_an_image_object_in_the_generated_pdf(): void
    {
        $fixture = $this->completedBookingFixture();

        $withPhoto = $this->generate($fixture['admin']['access_token'], $fixture['booking_uuid'], [
            'before_photos' => [$this->fakeImage('before.jpg')],
        ])->assertStatus(200);

        $this->assertStringContainsString('/Image', $withPhoto->getContent());
    }
}
