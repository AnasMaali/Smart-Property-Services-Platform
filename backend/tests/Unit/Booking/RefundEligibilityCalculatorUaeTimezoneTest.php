<?php

namespace Tests\Unit\Booking;

use App\Support\Booking\RefundEligibilityCalculator;
use Tests\TestCase;

/**
 * BLUE V1 - UAE business-timezone boundary coverage. BLUE V1 is a UAE-only
 * operation (Dubai/Abu Dhabi properties, AED-only payments) - Asia/Dubai
 * (UTC+4, no DST) is now `config('cancellation.timezone')`'s own default
 * (see config/cancellation.php), not just an override a test opts into.
 * This class deliberately does NOT set `cancellation.timezone` in its
 * own setUp() - it exercises the real application default, exactly as an
 * unconfigured production/staging environment would behave.
 *
 * Appointment/cancellation instants are stored under `config('app.timezone')`
 * (UTC) - the strings below are the UTC-equivalent of the Asia/Dubai wall
 * clock times in each example's own comment (Asia/Dubai is UTC+4, so
 * UTC = Asia/Dubai time minus 4 hours), matching exactly what a real
 * `appointment_slots.starts_at` / cancellation instant would contain.
 */
class RefundEligibilityCalculatorUaeTimezoneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cancellation.before_appointment_day_percentage' => 100,
            'cancellation.appointment_day_percentage' => 75,
        ]);
    }

    public function test_uses_asia_dubai_as_the_default_business_timezone(): void
    {
        $this->assertSame('Asia/Dubai', config('cancellation.timezone'));
    }

    public function test_the_night_before_in_dubai_is_still_100_percent(): void
    {
        // Appointment: 2026-08-30 10:00 Asia/Dubai = 2026-08-30 06:00 UTC.
        // Cancel:      2026-08-29 23:59 Asia/Dubai = 2026-08-29 19:59 UTC.
        // Still the calendar day BEFORE the appointment in Asia/Dubai (even
        // though 19:59 UTC is already "the 29th" everywhere) -> 100%.
        $result = RefundEligibilityCalculator::evaluate(
            '2026-08-30 06:00:00',
            '2026-08-29 19:59:00',
            '100.00',
            2
        );

        $this->assertTrue($result['cancellable']);
        $this->assertSame(100, $result['percentage']);
        $this->assertSame('BEFORE_APPOINTMENT_DAY', $result['reason_code']);
        $this->assertSame('100.000000', $result['amount']);
    }

    public function test_one_minute_after_dubai_midnight_drops_to_75_percent(): void
    {
        // Cancel: 2026-08-30 00:01 Asia/Dubai = 2026-08-29 20:01 UTC - only
        // two minutes of real wall-clock time after the previous case, but
        // now on the appointment's own calendar day in Asia/Dubai.
        $result = RefundEligibilityCalculator::evaluate(
            '2026-08-30 06:00:00',
            '2026-08-29 20:01:00',
            '100.00',
            2
        );

        $this->assertTrue($result['cancellable']);
        $this->assertSame(75, $result['percentage']);
        $this->assertSame('APPOINTMENT_DAY_BEFORE_START', $result['reason_code']);
        $this->assertSame('75.000000', $result['amount']);
    }

    public function test_at_appointment_start_in_dubai_is_rejected(): void
    {
        // Cancel exactly at starts_at (2026-08-30 10:00 Asia/Dubai) - the
        // appointment-started gate is a plain absolute-instant comparison,
        // independent of the business timezone conversion above.
        $result = RefundEligibilityCalculator::evaluate(
            '2026-08-30 06:00:00',
            '2026-08-30 06:00:00',
            '100.00',
            2
        );

        $this->assertFalse($result['cancellable']);
        $this->assertNull($result['percentage']);
        $this->assertNull($result['amount']);
        $this->assertSame('APPOINTMENT_ALREADY_STARTED', $result['reason_code']);
    }

    public function test_utc_midnight_boundary_does_not_leak_into_the_dubai_calendar_day(): void
    {
        // A cancellation at UTC midnight (2026-08-30 00:00 UTC) is already
        // 2026-08-30 04:00 in Asia/Dubai - i.e. ON the appointment's
        // calendar day in the business timezone, even though it is still
        // "the day before" in UTC. Proves the calendar-day comparison is
        // genuinely Asia/Dubai-based, not silently still UTC-based.
        $result = RefundEligibilityCalculator::evaluate(
            '2026-08-30 06:00:00',
            '2026-08-30 00:00:00',
            '100.00',
            2
        );

        $this->assertSame(75, $result['percentage']);
        $this->assertSame('APPOINTMENT_DAY_BEFORE_START', $result['reason_code']);
    }
}
