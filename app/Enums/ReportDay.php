<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

enum ReportDay: string
{
    use HasChoices;

    case Night = 'night';
    case RestDay = 'rest-day';
    case Holiday = 'holiday';

    public function label(): string
    {
        return match ($this) {
            self::Night => 'Night work',
            self::RestDay => 'Rest days',
            self::Holiday => 'Holidays',
        };
    }
}
