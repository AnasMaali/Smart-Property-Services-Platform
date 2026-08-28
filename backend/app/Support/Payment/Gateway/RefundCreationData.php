<?php

namespace App\Support\Payment\Gateway;

/**
 * Everything a PaymentGateway needs to start (or safely resume) exactly
 * one provider-side refund for exactly one `booking_refunds` obligation.
 * Built entirely from server-authoritative values already persisted by
 * App\Actions\Booking\CancelBookingAction inside the cancellation
 * transaction - never from client input.
 *
 * $providerPaymentReference is `payment_attempts.provider_session_reference`
 * (the Stripe PaymentIntent id) - the one unambiguous, always-non-null
 * identifier a successful payment_attempts row carries once payment
 * succeeded, unlike `provider_transaction_reference` which may hold either
 * a Charge id or a PaymentIntent id depending on whether Stripe exposed a
 * `latest_charge` at webhook time (see StripePaymentGateway::parseWebhook).
 * Stripe's refund API accepts a PaymentIntent id directly via the
 * `payment_intent` parameter and resolves the correct charge internally.
 */
final readonly class RefundCreationData
{
    public function __construct(
        public string $bookingRefundUuid,
        public string $providerPaymentReference,
        public string $amount,
        public string $currencyCode,
        public int $currencyMinorUnit,
        public string $providerIdempotencyKey,
    ) {}
}
