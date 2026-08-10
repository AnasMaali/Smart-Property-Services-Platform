<?php

namespace Tests\Unit\Payment;

use App\Support\Payment\Gateway\NormalizedPaymentOutcome;
use App\Support\Payment\Gateway\StripePaymentGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The "Stripe -> BLUE normalization principle": Stripe's own non-final
 * PaymentIntent states must never automatically read as BLUE FAILED. Pure
 * mapping, no Stripe API access required.
 */
class StripeStatusMappingTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: NormalizedPaymentOutcome}>
     */
    public static function statusProvider(): array
    {
        return [
            'requires_payment_method' => ['requires_payment_method', NormalizedPaymentOutcome::NON_TERMINAL],
            'requires_confirmation' => ['requires_confirmation', NormalizedPaymentOutcome::NON_TERMINAL],
            'requires_action' => ['requires_action', NormalizedPaymentOutcome::NON_TERMINAL],
            'processing' => ['processing', NormalizedPaymentOutcome::NON_TERMINAL],
            'requires_capture' => ['requires_capture', NormalizedPaymentOutcome::UNEXPECTED_NON_TERMINAL],
            'canceled' => ['canceled', NormalizedPaymentOutcome::CANCELED],
            'succeeded' => ['succeeded', NormalizedPaymentOutcome::SUCCEEDED],
        ];
    }

    #[DataProvider('statusProvider')]
    public function test_stripe_status_normalizes_correctly(string $stripeStatus, NormalizedPaymentOutcome $expected): void
    {
        $this->assertSame($expected, StripePaymentGateway::normalizeOutcome($stripeStatus));
    }

    public function test_a_declined_card_stays_non_terminal_not_failed(): void
    {
        // payment_intent.payment_failed still carries a PaymentIntent whose
        // status is requires_payment_method when a new payment method may
        // still be supplied - this must map to NON_TERMINAL, never
        // anything BLUE's state machine would treat as a failure.
        $this->assertSame(
            NormalizedPaymentOutcome::NON_TERMINAL,
            StripePaymentGateway::normalizeOutcome('requires_payment_method'),
        );
    }

    public function test_unknown_stripe_status_is_unrecognized(): void
    {
        $this->assertSame(NormalizedPaymentOutcome::UNRECOGNIZED, StripePaymentGateway::normalizeOutcome('some_future_stripe_status'));
    }
}
