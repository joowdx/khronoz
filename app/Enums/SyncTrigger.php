<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

enum SyncTrigger: string
{
    use HasChoices;

    /** The scheduler ran a pull. */
    case Scheduled = 'scheduled';

    /** Somebody asked for a pull now. */
    case Manual = 'manual';

    /** The device opened the connection and sent records unprompted. */
    case Push = 'push';

    /** Someone uploaded the device's own export. The only trigger anything writes. */
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Manual => 'Manual',
            self::Push => 'Pushed by device',
            self::Import => 'File import',
        };
    }
}
