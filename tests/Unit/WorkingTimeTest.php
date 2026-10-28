<?php

namespace App\Tests\Unit;

use App\Service\WorkingTime;
use PHPUnit\Framework\TestCase;

class WorkingTimeTest extends TestCase
{
    public function testWeekendsAreExcluded(): void
    {
        $wt = new WorkingTime(true);
        // Vendredi 12h -> lundi 12h = 1 jour ouvré (vendredi après-midi + lundi matin)
        $days = $wt->daysBetween(new \DateTimeImmutable('2026-10-23 12:00 UTC'), new \DateTimeImmutable('2026-10-26 12:00 UTC'));
        self::assertEqualsWithDelta(1.0, $days, 0.001);
    }

    public function testCalendarDaysWhenWeekendsIncluded(): void
    {
        $wt = new WorkingTime(false);
        $days = $wt->daysBetween(new \DateTimeImmutable('2026-10-23 12:00 UTC'), new \DateTimeImmutable('2026-10-26 12:00 UTC'));
        self::assertEqualsWithDelta(3.0, $days, 0.001);
    }

    public function testRemainingDays(): void
    {
        $wt = new WorkingTime(true);
        // Du mercredi 28 au mardi 3 inclus : me, je, ve, lu, ma = 5 jours ouvrés
        self::assertSame(5, $wt->remainingDays(new \DateTimeImmutable('2026-11-03 18:00'), new \DateTimeImmutable('2026-10-28 09:00')));
        self::assertSame(0, $wt->remainingDays(new \DateTimeImmutable('2026-10-27'), new \DateTimeImmutable('2026-10-28')));
    }
}
