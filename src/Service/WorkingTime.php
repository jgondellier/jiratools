<?php

namespace App\Service;

/**
 * Calcul de durées en jours, en excluant éventuellement les week-ends.
 */
class WorkingTime
{
    public function __construct(private readonly bool $excludeWeekends = true)
    {
    }

    public function daysBetween(\DateTimeInterface $from, ?\DateTimeInterface $to = null): float
    {
        $to ??= new \DateTimeImmutable();
        $start = \DateTimeImmutable::createFromInterface($from);
        $end = \DateTimeImmutable::createFromInterface($to);
        if ($end <= $start) {
            return 0.0;
        }
        if (!$this->excludeWeekends) {
            return ($end->getTimestamp() - $start->getTimestamp()) / 86400;
        }

        $seconds = 0;
        $cursor = $start;
        while ($cursor < $end) {
            $nextMidnight = $cursor->setTime(0, 0)->modify('+1 day');
            $sliceEnd = $nextMidnight < $end ? $nextMidnight : $end;
            if ((int) $cursor->format('N') < 6) {
                $seconds += $sliceEnd->getTimestamp() - $cursor->getTimestamp();
            }
            $cursor = $sliceEnd;
        }

        return $seconds / 86400;
    }

    /** Nombre de jours ouvrés (entiers) restant entre maintenant et une date de fin, aujourd'hui inclus */
    public function remainingDays(\DateTimeInterface $end, ?\DateTimeInterface $now = null): int
    {
        $now = \DateTimeImmutable::createFromInterface($now ?? new \DateTimeImmutable())->setTime(0, 0);
        $endDay = \DateTimeImmutable::createFromInterface($end)->setTime(0, 0);
        $days = 0;
        for ($d = $now; $d <= $endDay; $d = $d->modify('+1 day')) {
            if (!$this->excludeWeekends || (int) $d->format('N') < 6) {
                ++$days;
            }
        }

        return $days;
    }

    public function excludesWeekends(): bool
    {
        return $this->excludeWeekends;
    }
}
