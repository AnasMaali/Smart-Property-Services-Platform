<?php

namespace App\Support\Payment\Gateway;

/**
 * The one safe, provider-neutral shape PaymentGateway::parseWebhook()
 * produces for a REFUND lifecycle event - the counterpart to
 * NormalizedPaymentEvent for the payment side. App\Actions\Payment\
 * ProcessPaymentWebhookAction discriminates on `instanceof` between the
 * two: a NormalizedRefundEvent resolves against `booking_refunds` (by
 * provider_refund_reference, falling back to the still-PENDING row for
 * the resolved PaymentIntent), never against `payment_attempts`.
 *
 * $status is the provider's own refund status string (e.g. Stripe's
 * "succeeded" / "failed" / "pending" / "canceled") - normalized to a
 * BookingRefundStatuses code only inside the Action that consumes this,
 * exactly as NormalizedPaymentEvent keeps `providerStatusCode` separate
 * from the already-normalized `outcome`.
 */
final readonly class NormalizedRefundEvent
{
    public function __construct(
        public string $providerEventId,
        public string $eventType,
        public ?string $providerRefundReference,
        public ?string $providerPaymentReference,
        public string $status,
        public ?string $amount,
        public ?string $currencyCode,
        public ?string $failureCode,
        public ?string $failureMessage,
    ) {}
}
