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
}
