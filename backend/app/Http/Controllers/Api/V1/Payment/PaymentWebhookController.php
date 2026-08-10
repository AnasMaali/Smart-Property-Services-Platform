<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Actions\Payment\ProcessPaymentWebhookAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /v1/payments/webhooks/stripe - deliberately NOT behind auth.customer
 * (the caller is the payment provider's server, not a logged-in customer).
 * The provider's signature (verified inside ProcessPaymentWebhookAction
 * against the bound PaymentGateway) is the only authenticity check.
 *
 * $request->getContent() is read here, before any JSON decoding, and
 * passed through completely unmodified - Stripe (and this route's fake
 * test double) signs the exact raw bytes of the request body, so
 * re-encoding it in any way before verification would break every
 * signature.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, ProcessPaymentWebhookAction $action): JsonResponse
    {
        $rawBody = $request->getContent();

        $signatureHeaders = array_filter([
            'Stripe-Signature' => $request->header('Stripe-Signature'),
            'X-Fake-Signature' => $request->header('X-Fake-Signature'),
        ], static fn (?string $value) => $value !== null);

        $result = $action->handle($rawBody, $signatureHeaders);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
        ], $result['status']);
    }
}
