<?php

namespace App\Http\Controllers\Api\V1\Admin\Booking;

use App\Actions\Admin\Booking\GenerateAdminBookingCompletionReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GenerateAdminBookingCompletionReportRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Returns the generated PDF directly in the response body
 * (`application/pdf`) - never a permanent URL, since BLUE V1 keeps no
 * permanent Service Completion Report storage (see App\Actions\Admin\
 * Booking\GenerateAdminBookingCompletionReportAction's own docblock). The
 * optional `X-Report-Whatsapp-Url` header carries the server-built wa.me
 * link (App\Support\WhatsApp\WhatsAppLinkBuilder) so the Admin frontend can
 * offer "Open Customer WhatsApp" without a second round trip - it is
 * omitted entirely when the customer has no valid phone number, and the
 * frontend must treat its absence as "disable the action", never as an
 * error.
 */
final class GenerateAdminBookingCompletionReportController extends Controller
{
    public function __invoke(
        GenerateAdminBookingCompletionReportRequest $request,
        string $booking,
        GenerateAdminBookingCompletionReportAction $action,
    ): Response|JsonResponse {
        /** @var User $actor */
        $actor = $request->attributes->get('auth_user');

        $result = $action->handle(
            request: $request,
            actor: $actor,
            bookingUuid: $booking,
            completionNote: $request->string('completion_note')->toString() ?: null,
            beforePhotos: $request->file('before_photos', []),
            afterPhotos: $request->file('after_photos', []),
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'data' => $result['data'],
            ], $result['status']);
        }

        $headers = [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$result['data']['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($result['data']['whatsapp'] !== null) {
            $headers['X-Report-Whatsapp-Url'] = $result['data']['whatsapp']['url'];
        }

        return response($result['data']['pdf'], 200, $headers);
    }
}
