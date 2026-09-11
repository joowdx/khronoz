<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

/**
 * Mirrors syncs.trigger (varchar) and the syncs_trigger_valid CHECK
 * (trigger IN ('scheduled', 'manual', 'push', 'import')) —
 * docs/design/07-constraints.md.
 *
 * What started the run, which is not the same question as how the records
 * travelled (`TerminalProtocol`). A pull can be scheduled or kicked off by
 * hand; both are `Pull` on the terminal and differ here.
 *
 * Only `Import` has a writer in M5 (decision 40).
 */
enum SyncTrigger: string
{
    use HasChoices;

    /** The scheduler ran a pull. */
    case Scheduled = 'scheduled';

    /** Somebody asked for a pull now. */
    case Manual = 'manual';

    /** The device opened the connection and sent records unprompted. */
    case Push = 'push';

    /** Someone uploaded the device's own export. The only trigger M5 writes. */
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
