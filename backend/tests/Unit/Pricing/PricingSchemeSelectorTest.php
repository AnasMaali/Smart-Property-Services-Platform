<?php

namespace Tests\Unit\Pricing;

use App\Support\Pricing\PricingSchemeSelector;
use Carbon\Carbon;
use Tests\TestCase;

class PricingSchemeSelectorTest extends TestCase
{
    private PricingSchemeSelector $selector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->selector = new PricingSchemeSelector;
    }

    // ---- 25. scheme effective version selection --------------------------

    public function test_selects_the_open_ended_published_version(): void
    {
        $now = Carbon::parse('2026-06-01 00:00:00');

        $versions = [
            ['id' => 'draft', 'status' => 'DRAFT', 'effective_from' => null, 'effective_to' => null],
            ['id' => 'retired', 'status' => 'RETIRED', 'effective_from' => '2025-01-01 00:00:00', 'effective_to' => '2026-01-01 00:00:00'],
            ['id' => 'current', 'status' => 'PUBLISHED', 'effective_from' => '2026-01-01 00:00:00', 'effective_to' => null],
        ];

        $selected = $this->selector->selectCurrent($versions, $now);

        $this->assertSame('current', $selected['id']);
    }

    public function test_selects_the_finite_bounded_published_version_covering_now(): void
    {
        $now = Carbon::parse('2026-06-15 00:00:00');

        $versions = [
            ['id' => 'promo', 'status' => 'PUBLISHED', 'effective_from' => '2026-06-01 00:00:00', 'effective_to' => '2026-07-01 00:00:00'],
        ];

        $selected = $this->selector->selectCurrent($versions, $now);

        $this->assertSame('promo', $selected['id']);
    }

    // ---- 26. future scheme excluded ---------------------------------------

    public function test_future_published_version_is_excluded(): void
    {
        $now = Carbon::parse('2026-01-01 00:00:00');

        $versions = [
            ['id' => 'future', 'status' => 'PUBLISHED', 'effective_from' => '2026-06-01 00:00:00', 'effective_to' => null],
        ];

        $this->assertNull($this->selector->selectCurrent($versions, $now));
    }

    // ---- 27. expired scheme excluded ---------------------------------------

    public function test_expired_published_version_is_excluded(): void
    {
        $now = Carbon::parse('2026-08-01 00:00:00');

        $versions = [
            ['id' => 'expired', 'status' => 'PUBLISHED', 'effective_from' => '2026-01-01 00:00:00', 'effective_to' => '2026-07-01 00:00:00'],
        ];

        $this->assertNull($this->selector->selectCurrent($versions, $now));
    }

    public function test_no_scheme_at_all_returns_null(): void
    {
        $this->assertNull($this->selector->selectCurrent([], Carbon::now()));
    }

    public function test_effective_to_is_exclusive_upper_bound(): void
    {
        $boundary = Carbon::parse('2026-07-01 00:00:00');

        $versions = [
            ['id' => 'ending', 'status' => 'PUBLISHED', 'effective_from' => '2026-01-01 00:00:00', 'effective_to' => '2026-07-01 00:00:00'],
        ];

        // effective_to is exclusive: the instant it ends, it is no longer current.
        $this->assertNull($this->selector->selectCurrent($versions, $boundary));
    }
}
