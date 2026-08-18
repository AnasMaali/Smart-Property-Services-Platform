<?php

namespace App\Support\Contract\Billing\Gateway;

use RuntimeException;

/**
 * Thrown by StripeContractBillingGateway when a Stripe API/webhook
 * operation is attempted without the required key configured - mirrors
 * App\Support\Payment\Gateway\PaymentGatewayNotConfiguredException exactly.
 * Never carries the missing key's name or value in a way that could reach a
 * client response; callers must translate this into a safe failure outcome,
 * never a fake success.
 */
final class ContractBillingGatewayNotConfiguredException extends RuntimeException {}
