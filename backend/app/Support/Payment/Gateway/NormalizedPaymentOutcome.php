<?php

namespace App\Support\Payment\Gateway;

/**
 * The provider-neutral outcome classification every PaymentGateway
 * implementation must normalize its own provider-specific lifecycle into,
 * encoding the "Stripe -> BLUE normalization principle": a provider's own
 * non-final/in-progress states must never automatically read as BLUE
 * FAILED. Only SUCCEEDED and CANCELED are terminal here; the raw
 * provider status string is preserved separately (see
 * NormalizedPaymentEvent::$providerStatusCode / ProviderPaymentState::
 * $providerStatusCode) so nothing about the provider's own state is lost
 * even though it is not part of this classification.
 */
enum NormalizedPaymentOutcome: string
{
    /**
     * The provider has confirmed the payment succeeded (Stripe:
     * `succeeded`). Maps to BLUE PENDING -> SUCCESSFUL.
     */
    case SUCCEEDED = 'SUCCEEDED';

    /**
     * The provider has confirmed the payment/session was canceled and is
     * not recoverable (Stripe: `canceled`). Maps to BLUE PENDING ->
     * CANCELLED.
     */
    case CANCELED = 'CANCELED';

    /**
     * The provider's own object still exists and can still complete or be
     * retried (Stripe: `requires_payment_method`, `requires_confirmation`,
     * `requires_action`, `processing`). BLUE MUST remain PENDING - this is
     * explicitly not a failure.
     */
    case NON_TERMINAL = 'NON_TERMINAL';

    /**
     * A provider state BLUE V1 does not intentionally use and did not
     * request (Stripe: `requires_capture`, since every PaymentIntent BLUE
     * creates uses automatic capture). Treated as PENDING plus an
     * observability flag - never SUCCESSFUL, never FAILED - because it
     * indicates a configuration/integration problem, not a customer-facing
     * payment result.
     */
    case UNEXPECTED_NON_TERMINAL = 'UNEXPECTED_NON_TERMINAL';

    /**
     * An authentic provider event/state this gateway does not map to any
     * of the above (e.g. a refund event in Phase 6A, or a future Stripe
     * event type). Never mutates payment attempt state; the webhook ledger
     * entry is marked IGNORED.
     */
    case UNRECOGNIZED = 'UNRECOGNIZED';
}
