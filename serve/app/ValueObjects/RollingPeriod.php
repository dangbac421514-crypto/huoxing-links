<?php

namespace App\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class RollingPeriod
{
    private function __construct(
        private CarbonImmutable $start,
        private CarbonImmutable $end,
        private int $anchorDay,
    ) {}

    public static function forAnchor(CarbonImmutable $anchor, CarbonImmutable $at): self
    {
        $anchor = $anchor->setTimezone('Asia/Shanghai');
        $at = $at->setTimezone('Asia/Shanghai');

        if ($anchor->gt($at)) {
            throw new InvalidArgumentException('会员锚点不能晚于当前时间');
        }

        $anchorDay = $anchor->day;
        $candidate = self::monthDate($anchor, $at->year, $at->month, $anchorDay);
        if ($candidate->gt($at)) {
            $previous = $at->subMonthNoOverflow();
            $candidate = self::monthDate($anchor, $previous->year, $previous->month, $anchorDay);
        }

        $next = $candidate->addMonthNoOverflow();

        return new self(
            $candidate,
            self::monthDate($anchor, $next->year, $next->month, $anchorDay),
            $anchorDay,
        );
    }

    private static function monthDate(CarbonImmutable $anchor, int $year, int $month, int $day): CarbonImmutable
    {
        $first = CarbonImmutable::create(
            $year,
            $month,
            1,
            $anchor->hour,
            $anchor->minute,
            $anchor->second,
            'Asia/Shanghai',
        );

        return $first->setDay(min($day, $first->daysInMonth));
    }

    public function start(): CarbonImmutable
    {
        return $this->start;
    }

    public function end(): CarbonImmutable
    {
        return $this->end;
    }

    public function anchorDay(): int
    {
        return $this->anchorDay;
    }

    public function contains(CarbonImmutable $instant): bool
    {
        $instant = $instant->setTimezone('Asia/Shanghai');

        return $instant->gte($this->start) && $instant->lt($this->end);
    }
}
