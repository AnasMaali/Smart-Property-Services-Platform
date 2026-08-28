<?php

namespace App\Support\Payment\Gateway;

/**
 * Converts between BLUE's decimal(19,6) amount strings and a provider's
 * integer minor-unit representation (e.g. AED fils), using currencies.
 * minor_unit - the same field CheckoutPresenter already exposes as
 * `currency.decimal_places`. bcmath only, matching the bcadd convention
 * PricingResultAggregator already uses - never float arithmetic on money.
 *
 * BLUE V1 Phase B20 fix - both methods below use standard monetary
 * half-up rounding at the target scale (never truncation): a checkout
 * total is already exact at the currency's minor-unit precision, so this
 * changed nothing for App\Support\Payment\Gateway\StripePaymentGateway::
 * createPayment(); it matters for App\Support\Booking\
 * RefundEligibilityCalculator's 75%/100% split, which can produce a
 * decimal(19,6) amount with more precision than the currency actually
 * supports (e.g. AED 99.99 x 75% = AED 74.9925) - truncating that would
 * silently under-refund by a fraction of a minor unit. Round-half-up at
 * the target scale is the same rule App\Support\Booking\
 * RefundEligibilityCalculator now applies (via roundToMinorUnit()) to
 * normalize the amount it persists, so the amount recorded on
 * `bookings.cancellation_refund_amount` / `booking_refunds.
 * requested_amount` and the integer this class hands to Stripe always
 * represent the exact same final monetary amount - never two different
 * numbers that happen to be close.
 */
final class MinorUnitConverter
{
    public static function toMinorUnits(string $decimalAmount, int $minorUnit): int
    {
        return (int) self::roundHalfUp($decimalAmount, bcpow('10', (string) $minorUnit), 0);
    }

    public static function toDecimalString(int $minorUnits, int $minorUnit): string
    {
        return bcdiv((string) $minorUnits, bcpow('10', (string) $minorUnit), 6);
    }

    /**
     * Rounds a decimal(19,6) amount string to exactly $minorUnit decimal
     * places (e.g. 2 for AED), half-up - the one place App\Support\
     * Booking\RefundEligibilityCalculator normalizes a freshly computed
     * refund amount before it is ever persisted, so what gets recorded is
     * already the exact amount toMinorUnits() above will send to Stripe,
     * never a higher-precision figure that only gets rounded later.
     */
    public static function roundToMinorUnit(string $decimalAmount, int $minorUnit): string
    {
        return bcdiv(
            self::roundHalfUp($decimalAmount, bcpow('10', (string) $minorUnit), 0),
            bcpow('10', (string) $minorUnit),
            $minorUnit
        );
    }

    /**
     * $decimalAmount * $multiplier, rounded half-up to $scale decimal
     * places. Only ever called here with non-negative money amounts (a
     * refund/payment amount can never be negative), so the classic
     * bcmath "add half a unit at the target scale, then truncate" trick
     * is a safe, exact half-up round - it would need a sign check to be
     * correct for negative inputs, which this codebase never produces.
     */
    private static function roundHalfUp(string $decimalAmount, string $multiplier, int $scale): string
    {
        $scaled = bcmul($decimalAmount, $multiplier, 6);
        $halfUnit = bcdiv('5', bcpow('10', (string) ($scale + 1)), $scale + 1);

        return bcadd($scaled, $halfUnit, $scale);
    }
}
