<?php

namespace Tests\Unit\Booking;

use App\Support\Booking\RefundEligibilityCalculator;
use Tests\TestCase;

/**
 * BLUE V1 Phase B20 fix - pure unit coverage of the exact rounding rule
 * proven by the audit's three required AED examples (2 minor units):
 * standard monetary half-up rounding via App\Support\Payment\Gateway\
 * MinorUnitConverter::roundToMinorUnit(), applied BEFORE the amount is
 * ever persisted - never truncation, never a raw >2-decimal figure left
 * for a later step to round differently. The result is then padded back
 * to decimal(19,6) - the same 6-decimal string convention every other
 * money field in this API already uses - so the returned figure is
 * numerically normalized (74.99, never 74.9925) while still matching the
 * exact string a later DB round-trip read of `bookings.
 * cancellation_refund_amount` / `booking_refunds.requested_amount` would
 * produce.
 */
class RefundEligibilityCalculatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cancellation.timezone' => 'UTC',
            'cancellation.before_appointment_day_percentage' => 100,
            'cancellation.appointment_day_percentage' => 75,
        ]);
    }

    public function test_100_aed_at_75_percent_normalizes_to_exactly_75_00(): void
    {
        $result = RefundEligibilityCalculator::evaluate(
            '2026-09-15 10:00:00',
            '2026-09-15 05:00:00',
            '100.00',
            2
        );

        $this->assertTrue($result['cancellable']);
        $this->assertSame(75, $result['percentage']);
        $this->assertSame('75.000000', $result['amount']);
    }

    public function test_99_99_aed_at_75_percent_normalizes_to_74_99_not_74_9925(): void
    {
        $result = RefundEligibilityCalculator::evaluate(
            '2026-09-15 10:00:00',
            '2026-09-15 05:00:00',
            '99.99',
            2
        );

        $this->assertSame(75, $result['percentage']);
        // 99.99 x 75% = 74.9925 -> half-up at 2 decimals rounds DOWN to
        // 74.99 (the third decimal, 2, is below 5) - never the raw
        // 74.992500 figure, and never 75.00.
        $this->assertSame('74.990000', $result['amount']);
    }

    public function test_0_01_aed_at_75_percent_rounds_up_to_the_smallest_unit_never_zero(): void
    {
        $result = RefundEligibilityCalculator::evaluate(
            '2026-09-15 10:00:00',
            '2026-09-15 05:00:00',
            '0.01',
            2
        );

        $this->assertSame(75, $result['percentage']);
        // 0.01 x 75% = 0.0075 -> half-up at 2 decimals rounds UP to the
        // smallest refundable unit (0.01), never truncates to 0.00.
        $this->assertSame('0.010000', $result['amount']);
    }

    public function test_100_percent_before_appointment_day_needs_no_rounding(): void
    {
        $result = RefundEligibilityCalculator::evaluate(
            '2026-09-15 10:00:00',
            '2026-09-14 20:00:00',
            '99.99',
            2
        );

        $this->assertSame(100, $result['percentage']);
        $this->assertSame('99.990000', $result['amount']);
    }
}
