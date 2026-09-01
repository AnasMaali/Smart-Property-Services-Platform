<?php

namespace App\Actions\Admin\Booking;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminBookingPresenter;
use App\Support\Admin\Reports\AdminBookingCompletionReportPhotoProcessor;
use App\Support\Admin\Reports\AdminReportPdf;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use App\Support\WhatsApp\WhatsAppLinkBuilder;
use App\Support\WhatsApp\WhatsAppMessagePresenter;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Service Completion Report - a read/export workflow, never a Booking
 * mutation: this Action's own DB writes are limited to the
 * App\Support\Admin\AdminAuditLogger row it appends on success. Every fact
 * printed on the report comes straight from App\Support\Admin\
 * AdminBookingPresenter::detail() - the exact same authoritative,
 * historical-snapshot shape the Admin Booking detail page already renders -
 * never a second, parallel computation of a total, a technician name, or a
 * payment amount.
 *
 * Eligibility: only a Booking whose current status is COMPLETED may have a
 * report generated (an unknown/malformed Booking UUID -> 404; any other
 * status, including CANCELLED -> 409). Enforced here, not only by hiding
 * the "Generate Report" button client-side.
 *
 * Before/After photos are never written to disk or the database - see
 * App\Support\Admin\Reports\AdminBookingCompletionReportPhotoProcessor's own
 * docblock. The generated PDF itself is returned directly in the HTTP
 * response body (App\Http\Controllers\Api\V1\Admin\Booking\
 * GenerateAdminBookingCompletionReportController) and is never written to
 * storage/ or a database column either - BLUE V1 keeps no permanent report
 * history, by design (see this feature's PR description section 19).
 */
final class GenerateAdminBookingCompletionReportAction
{
    use BuildsCartResult;

    /**
     * @param  array<int, UploadedFile>  $beforePhotos
     * @param  array<int, UploadedFile>  $afterPhotos
     * @return array<string, mixed>
     */
    public function handle(
        Request $request,
        User $actor,
        string $bookingUuid,
        ?string $completionNote,
        array $beforePhotos,
        array $afterPhotos,
    ): array {
        try {
            $bookingIdBinary = UuidBinary::toBinary($bookingUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Booking not found.');
        }

        $row = DB::table('bookings')
            ->join('carts', 'carts.id', '=', 'bookings.cart_id')
            ->where('bookings.id', $bookingIdBinary)
            ->first(['bookings.*', 'carts.customer_user_id', 'carts.currency_id as cart_currency_id']);

        if ($row === null) {
            return $this->notFound('Booking not found.');
        }

        $statusCode = DB::table('booking_statuses')->where('id', $row->status_id)->value('code');

        if ($statusCode !== 'COMPLETED') {
            return $this->conflict('A Service Completion Report can only be generated for a completed Booking.');
        }

        $booking = AdminBookingPresenter::detail($row);

        try {
            $beforeImages = array_map(
                fn (UploadedFile $file): string => AdminBookingCompletionReportPhotoProcessor::toDataUri($file),
                array_values($beforePhotos),
            );
            $afterImages = array_map(
                fn (UploadedFile $file): string => AdminBookingCompletionReportPhotoProcessor::toDataUri($file),
                array_values($afterPhotos),
            );
        } catch (RuntimeException $exception) {
            return $this->unprocessable($exception->getMessage());
        }

        $completionNote = $completionNote !== null && trim($completionNote) !== '' ? trim($completionNote) : null;

        $pdf = AdminReportPdf::render('admin.reports.pdf.booking-completion', [
            'booking' => $booking,
            'completionNote' => $completionNote,
            'beforeImages' => $beforeImages,
            'afterImages' => $afterImages,
            'generatedAt' => now()->toIso8601String(),
        ]);

        AdminAuditLogger::record(
            request: $request,
            actor: $actor,
            actionCode: 'SERVICE_COMPLETION_REPORT_GENERATED',
            entityType: 'BOOKING',
            entityIdentifier: $bookingUuid,
            newValues: [
                'has_before_photos' => $beforeImages !== [],
                'before_photo_count' => count($beforeImages),
                'has_after_photos' => $afterImages !== [],
                'after_photo_count' => count($afterImages),
                'has_completion_note' => $completionNote !== null,
            ],
        );

        return $this->ok(200, 'Service Completion Report generated successfully.', [
            'pdf' => $pdf,
            'filename' => self::filename($booking['booking_number']),
            'whatsapp' => $this->whatsappHandoff($booking, $beforeImages !== [] || $afterImages !== []),
        ]);
    }

    /**
     * BLUE V1 Simple WhatsApp Handoff - `null` (never a broken/unsafe URL)
     * when the customer's phone is missing or not a valid E.164 number, so
     * the caller can safely disable "Open Customer WhatsApp" rather than
     * offer a dead link. The message text deliberately never claims the
     * report was sent - only that it is ready and attached; opening
     * WhatsApp is not evidence of delivery (see this feature's PR
     * description section 17).
     *
     * @param  array<string, mixed>  $booking
     * @return array{message: string, url: string}|null
     */
    private function whatsappHandoff(array $booking, bool $hasPhotos): ?array
    {
        $message = WhatsAppMessagePresenter::completionReportReady([
            'customer_name' => $booking['customer']['full_name'] ?? 'Customer',
            'booking_number' => $booking['booking_number'],
            'has_photos' => $hasPhotos,
        ]);

        return WhatsAppLinkBuilder::build($booking['customer']['phone_number'] ?? null, $message);
    }

    /**
     * `BOOKING_NUMBER` is a server-generated identifier, but the resulting
     * string still lands in a `Content-Disposition` header - stripped down
     * to a safe filename character set defensively, exactly like this
     * feature's PR description section 11 requires for any user/admin-
     * influenced value that reaches an HTTP header.
     */
    private static function filename(string $bookingNumber): string
    {
        $safe = preg_replace('/[^A-Za-z0-9\-]/', '', $bookingNumber);

        return 'BLUE-Service-Report-'.($safe !== '' && $safe !== null ? $safe : 'Booking').'.pdf';
    }
}
