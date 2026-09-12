<?php

namespace App\Attendance;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class Week
{
    private function __construct(
        private int $total,
        private int $dailyExcess,
        private ?int $ceiling,
    ) {}

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function bounds(CarbonInterface $date): array
    {
        $monday = CarbonImmutable::instance($date)->startOfWeek(CarbonInterface::MONDAY)->startOfDay();

        return [$monday, $monday->addDays(6)];
    }

    /**
     * @param  list<array{worked: int, credited: int, excess: int}>  $workdays
     */
    public static function of(array $workdays, ?int $ceiling): self
    {
        $total = 0;
        $dailyExcess = 0;

        foreach ($workdays as $workday) {
            $total += $workday['worked'] + $workday['credited'];
            $dailyExcess += $workday['excess'];
        }

        return new self($total, $dailyExcess, $ceiling);
    }

    public function total(): int
    {
        return $this->total;
    }

    public function dailyExcess(): int
    {
        return $this->dailyExcess;
    }

    public function weeklyOnly(): int
    {

        if ($this->ceiling === null) {
            return 0;
        }

        return max(0, $this->total - $this->ceiling - $this->dailyExcess);
    }

    public function overtime(): int
    {
        return $this->dailyExcess() + $this->weeklyOnly();
    }
}
