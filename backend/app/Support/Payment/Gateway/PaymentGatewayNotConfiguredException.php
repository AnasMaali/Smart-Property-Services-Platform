<?php

namespace App\Support\Payment\Gateway;

use RuntimeException;

/**
 * Thrown by StripePaymentGateway when a Stripe API/webhook operation is
 * attempted without the required key configured (no Stripe account exists
 * yet for BLUE V1 - see docs/api-contracts/payments-v1.md). Never carries
 * the missing key's name or value in a way that could reach a client
 * response; callers must translate this into the same generic "payment
 * provider unavailable" outcome as PaymentCreationOutcome::DEFINITIVE_FAILURE
 * / UNKNOWN, never a fake success.
 */
final class PaymentGatewayNotConfiguredException extends RuntimeException {}
