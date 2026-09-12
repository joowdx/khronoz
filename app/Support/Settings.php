<?php

namespace App\Support;

use App\Enums\MissingSide;
use App\Models\Agency;

final class Settings
{
    public function __construct(private readonly Agency $agency) {}

    public function nightFrom(): string
    {
        return $this->get('night_from') ?? '18:00';
    }

    public function overtimeAfterWeekly(): ?int
    {
        $hours = $this->get('overtime_after_weekly');

        return $hours === null ? null : (int) $hours * 60;
    }

    public function occurrences(): bool
    {
        return $this->get('occurrences') ?? true;
    }

    public function suspensionCharge(): bool
    {
        return $this->get('suspension_charge') ?? true;
    }

    public function premiumHours(): bool
    {
        return $this->get('premium_hours') ?? false;
    }

    public function overtimeGates(): bool
    {
        return $this->get('overtime_gates') ?? true;
    }

    public function missingSide(): MissingSide
    {
        $value = $this->get('missing_side');

        return $value === null ? MissingSide::Void : MissingSide::from($value);
    }

    private function get(string $key): mixed
    {
        return ($this->agency->settings ?? [])[$key] ?? null;
    }
}
