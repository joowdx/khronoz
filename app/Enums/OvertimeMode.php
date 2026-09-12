<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

enum OvertimeMode: string
{
    use HasChoices;

    /** Paid at the overtime rate. */
    case Pay = 'pay';

    /** Earned as compensatory overtime credit, drawn later as a `cto` exemption. */
    case Cto = 'cto';

    public function label(): string
    {
        return match ($this) {
            self::Pay => 'Overtime pay',
            self::Cto => 'Compensatory time off',
        };
    }
}
