<?php

namespace App\Support\Payment\Gateway;

/**
 * Converts between BLUE's decimal(19,6) amount strings and a provider's
 * integer minor-unit representation (e.g. AED fils), using currencies.
 * minor_unit - the same field CheckoutPresenter already exposes as
 * `currency.decimal_places`. bcmath only, matching the bcadd convention
 * PricingResultAggregator already uses - never float arithmetic on money.
 */
final class MinorUnitConverter
{
    public static function toMinorUnits(string $decimalAmount, int $minorUnit): int
    {
        return (int) bcmul($decimalAmount, bcpow('10', (string) $minorUnit), 0);
    }

    public static function toDecimalString(int $minorUnits, int $minorUnit): string
    {
        return bcdiv((string) $minorUnits, bcpow('10', (string) $minorUnit), 6);
    }
}
