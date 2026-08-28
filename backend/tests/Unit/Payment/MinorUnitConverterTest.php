<?php

namespace Tests\Unit\Payment;

use App\Support\Payment\Gateway\MinorUnitConverter;
use Tests\TestCase;

class MinorUnitConverterTest extends TestCase
{
    public function test_decimal_to_minor_units_for_two_decimal_currency(): void
    {
        $this->assertSame(10000, MinorUnitConverter::toMinorUnits('100.000000', 2));
        $this->assertSame(150, MinorUnitConverter::toMinorUnits('1.500000', 2));
    }

    public function test_minor_units_back_to_decimal_string(): void
    {
        $this->assertSame('100.000000', MinorUnitConverter::toDecimalString(10000, 2));
        $this->assertSame('1.500000', MinorUnitConverter::toDecimalString(150, 2));
    }

    public function test_round_trip_is_lossless(): void
    {
        $amount = '42.750000';
        $minorUnits = MinorUnitConverter::toMinorUnits($amount, 2);

        $this->assertSame($amount, MinorUnitConverter::toDecimalString($minorUnits, 2));
    }

    // -----------------------------------------------------------------
    // BLUE V1 Phase B20 fix (audit LOW #3) - toMinorUnits() rounds
    // half-up at the target scale rather than truncating, since a 75%
    // refund split can produce sub-minor-unit remainders a checkout
    // total never did.
    // -----------------------------------------------------------------

    public function test_sub_minor_unit_remainder_rounds_half_up_not_down(): void
    {
        // AED 99.99 x 75% = AED 74.9925 -> 7499.25 minor units, which
        // rounds DOWN to 7499 (74.99) since .25 < .5 - not truncation
        // coincidentally landing on the same value.
        $this->assertSame(7499, MinorUnitConverter::toMinorUnits('74.992500', 2));

        // AED 0.01 x 75% = AED 0.0075 -> 0.75 minor units, which rounds
        // UP to 1 (AED 0.01) - truncation would have produced 0, a
        // zero-value Stripe refund.
        $this->assertSame(1, MinorUnitConverter::toMinorUnits('0.007500', 2));
    }

    public function test_round_to_minor_unit_normalizes_a_higher_precision_decimal(): void
    {
        $this->assertSame('75.00', MinorUnitConverter::roundToMinorUnit('75.000000', 2));
        $this->assertSame('74.99', MinorUnitConverter::roundToMinorUnit('74.992500', 2));
        $this->assertSame('0.01', MinorUnitConverter::roundToMinorUnit('0.007500', 2));
    }

    public function test_round_to_minor_unit_result_converts_to_the_same_minor_units_as_the_raw_amount(): void
    {
        // The whole point of normalizing at persistence time: the
        // normalized decimal amount and the raw pre-rounding amount must
        // convert to the IDENTICAL integer Stripe amount.
        $raw = '74.992500';
        $normalized = MinorUnitConverter::roundToMinorUnit($raw, 2);

        $this->assertSame(
            MinorUnitConverter::toMinorUnits($raw, 2),
            MinorUnitConverter::toMinorUnits($normalized, 2)
        );
    }
}
