<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

enum CadenceKind: string
{
    use HasChoices;

    case Weekly = 'weekly';
    case Fortnightly = 'fortnightly';
    case Semimonthly = 'semimonthly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly',
            self::Fortnightly => 'Fortnightly',
            self::Semimonthly => 'Semi-monthly',
            self::Monthly => 'Monthly',
        };
    }
}
