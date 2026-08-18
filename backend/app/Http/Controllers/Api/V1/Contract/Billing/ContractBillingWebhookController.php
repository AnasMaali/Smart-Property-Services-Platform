<?php

namespace App\Http\Controllers\Api\V1\Contract\Billing;

use App\Actions\Contract\Billing\ProcessContractBillingWebhookAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /v1/contracts/billing/webhooks/stripe (BLUE V1 Phase 11) -
 * deliberately NOT behind auth.customer (the caller is Stripe's server, not
 * a logged-in customer), and deliberately a SEPARATE endpoint/controller
 * from App\Http\Controllers\Api\V1\Payment\PaymentWebhookController - a
 * different Stripe webhook endpoint, with its own signing secret, carrying
 * Subscription/Invoice/Checkout Session events only, never a PaymentIntent
 * one.
 *
 * $request->getContent() is read here, before any JSON decoding, and
 * passed through completely unmodified - Stripe signs the exact raw bytes
 * of the request body, so re-encoding it in any way before verification
 * would break every signature (identical rule to PaymentWebhookController).
 */
class ContractBillingWebhookController extends Controller
{
    public function __invoke(Request $request, ProcessContractBillingWebhookAction $action): JsonResponse
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
