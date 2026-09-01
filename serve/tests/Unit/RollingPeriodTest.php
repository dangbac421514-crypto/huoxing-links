<?php

namespace Tests\Unit;

use App\ValueObjects\RollingPeriod;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RollingPeriodTest extends TestCase
{
    public function test_fifteenth_anchor_is_half_open(): void
    {
        $anchor = CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Shanghai');
        $period = RollingPeriod::forAnchor($anchor, CarbonImmutable::parse('2026-10-15 09:59:59', 'Asia/Shanghai'));

        $this->assertSame('2026-09-15 10:00:00', $period->start()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-15 10:00:00', $period->end()->format('Y-m-d H:i:s'));
        $this->assertTrue($period->contains($period->start()));
        $this->assertFalse($period->contains($period->end()));
    }

    public function test_period_contains_only_start_inclusive_end_exclusive(): void
    {
        $period = RollingPeriod::forAnchor(
            CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Shanghai'),
            CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Shanghai'),
        );

        $this->assertFalse($period->contains($period->start()->subSecond()));
        $this->assertTrue($period->contains($period->start()->addSecond()));
        $this->assertFalse($period->contains($period->end()));
    }

    public function test_fifteenth_anchor_at_exact_boundary_starts_next_period(): void
    {
        $period = RollingPeriod::forAnchor(
            CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Shanghai'),
            CarbonImmutable::parse('2026-10-15 10:00:00', 'Asia/Shanghai'),
        );

        $this->assertSame('2026-10-15 10:00:00', $period->start()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-15 10:00:00', $period->end()->format('Y-m-d H:i:s'));
    }

    public function test_twenty_ninth_anchor_clamps_in_non_leap_february_and_restores(): void
    {
        $anchor = CarbonImmutable::parse('2024-01-29 08:00:00', 'Asia/Shanghai');
        $february = RollingPeriod::forAnchor($anchor, CarbonImmutable::parse('2025-02-28 07:59:59', 'Asia/Shanghai'));
        $march = RollingPeriod::forAnchor($anchor, CarbonImmutable::parse('2025-03-29 08:00:00', 'Asia/Shanghai'));

        $this->assertSame('2025-01-29 08:00:00', $february->start()->format('Y-m-d H:i:s'));
        $this->assertSame('2025-02-28 08:00:00', $february->end()->format('Y-m-d H:i:s'));
        $this->assertSame('2025-03-29 08:00:00', $march->start()->format('Y-m-d H:i:s'));
        $this->assertSame(29, $march->anchorDay());
    }

    public function test_twenty_ninth_anchor_uses_february_twenty_ninth_in_leap_year(): void
    {
        $period = RollingPeriod::forAnchor(
            CarbonImmutable::parse('2024-01-29 08:00:00', 'Asia/Shanghai'),
            CarbonImmutable::parse('2024-02-29 08:00:00', 'Asia/Shanghai'),
        );

        $this->assertSame('2024-02-29 08:00:00', $period->start()->format('Y-m-d H:i:s'));
        $this->assertSame('2024-03-29 08:00:00', $period->end()->format('Y-m-d H:i:s'));
    }

    public function test_thirtieth_anchor_clamps_february_and_restores_in_april_and_may(): void
    {
        $anchor = CarbonImmutable::parse('2025-01-30 08:00:00', 'Asia/Shanghai');
        $february = RollingPeriod::forAnchor($anchor, CarbonImmutable::parse('2025-02-28 07:59:59', 'Asia/Shanghai'));
        $april = RollingPeriod::forAnchor($anchor, CarbonImmutable::parse('2025-04-30 08:00:00', 'Asia/Shanghai'));
        $may = RollingPeriod::forAnchor($anchor, CarbonImmutable::parse('2025-05-30 08:00:00', 'Asia/Shanghai'));

        $this->assertSame('2025-01-30 08:00:00', $february->start()->format('Y-m-d H:i:s'));
        $this->assertSame('2025-02-28 08:00:00', $february->end()->format('Y-m-d H:i:s'));
        $this->assertSame('2025-04-30 08:00:00', $april->start()->format('Y-m-d H:i:s'));
        $this->assertSame('2025-05-30 08:00:00', $april->end()->format('Y-m-d H:i:s'));
        $this->assertSame('2025-05-30 08:00:00', $may->start()->format('Y-m-d H:i:s'));
    }

    public function test_thirty_first_anchor_clamps_only_the_missing_month(): void
    {
        $anchor = CarbonImmutable::parse('2024-01-31 08:00:00', 'Asia/Shanghai');
        $february = RollingPeriod::forAnchor($anchor, CarbonImmutable::parse('2024-02-29 07:59:59', 'Asia/Shanghai'));
        $march = RollingPeriod::forAnchor($anchor, CarbonImmutable::parse('2024-02-29 08:00:00', 'Asia/Shanghai'));

        $this->assertSame('2024-01-31 08:00:00', $february->start()->format('Y-m-d H:i:s'));
        $this->assertSame('2024-02-29 08:00:00', $february->end()->format('Y-m-d H:i:s'));
        $this->assertSame('2024-02-29 08:00:00', $march->start()->format('Y-m-d H:i:s'));
        $this->assertSame('2024-03-31 08:00:00', $march->end()->format('Y-m-d H:i:s'));
        $this->assertSame(31, $march->anchorDay());
    }

    public function test_thirty_first_anchor_clamps_non_leap_february(): void
    {
        $period = RollingPeriod::forAnchor(
            CarbonImmutable::parse('2025-01-31 08:00:00', 'Asia/Shanghai'),
            CarbonImmutable::parse('2025-02-28 08:00:00', 'Asia/Shanghai'),
        );

        $this->assertSame('2025-02-28 08:00:00', $period->start()->format('Y-m-d H:i:s'));
        $this->assertSame('2025-03-31 08:00:00', $period->end()->format('Y-m-d H:i:s'));
    }

    public function test_thirty_first_anchor_restores_after_april(): void
    {
        $period = RollingPeriod::forAnchor(
            CarbonImmutable::parse('2025-01-31 08:00:00', 'Asia/Shanghai'),
            CarbonImmutable::parse('2025-05-31 08:00:00', 'Asia/Shanghai'),
        );

        $this->assertSame('2025-05-31 08:00:00', $period->start()->format('Y-m-d H:i:s'));
        $this->assertSame('2025-06-30 08:00:00', $period->end()->format('Y-m-d H:i:s'));
    }

    public function test_anchor_time_and_timezone_are_preserved_as_shanghai_boundaries(): void
    {
        $period = RollingPeriod::forAnchor(
            CarbonImmutable::parse('2026-09-15 10:00:00', 'UTC'),
            CarbonImmutable::parse('2026-10-15 01:00:00', 'UTC'),
        );

        $this->assertSame('Asia/Shanghai', $period->start()->getTimezone()->getName());
        $this->assertSame('2026-09-15 18:00:00', $period->start()->format('Y-m-d H:i:s'));
    }

    public function test_evaluation_before_anchor_day_uses_previous_month(): void
    {
        $period = RollingPeriod::forAnchor(
            CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Shanghai'),
            CarbonImmutable::parse('2026-10-15 09:59:59', 'Asia/Shanghai'),
        );

        $this->assertSame('2026-09-15 10:00:00', $period->start()->format('Y-m-d H:i:s'));
    }

    public function test_anchor_after_explicit_evaluation_instant_is_invalid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RollingPeriod::forAnchor(
            CarbonImmutable::parse('2026-10-15 10:00:01', 'Asia/Shanghai'),
            CarbonImmutable::parse('2026-10-15 10:00:00', 'Asia/Shanghai'),
        );
    }

    public function test_future_wall_clock_does_not_invalidate_anchor_for_historical_evaluation(): void
    {
        $period = RollingPeriod::forAnchor(
            CarbonImmutable::parse('2099-09-15 10:00:00', 'Asia/Shanghai'),
            CarbonImmutable::parse('2100-10-15 09:59:59', 'Asia/Shanghai'),
        );

        $this->assertSame('2100-09-15 10:00:00', $period->start()->format('Y-m-d H:i:s'));
    }

    public function test_inputs_are_not_mutated(): void
    {
        $anchor = CarbonImmutable::parse('2024-01-31 08:00:00', 'Asia/Shanghai');
        $at = CarbonImmutable::parse('2024-02-29 07:59:59', 'Asia/Shanghai');

        RollingPeriod::forAnchor($anchor, $at);

        $this->assertSame('2024-01-31 08:00:00', $anchor->format('Y-m-d H:i:s'));
        $this->assertSame('2024-02-29 07:59:59', $at->format('Y-m-d H:i:s'));
    }
}
