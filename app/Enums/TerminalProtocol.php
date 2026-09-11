<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

/**
 * Mirrors terminals.protocol (varchar) and the terminals_protocol_valid CHECK
 * (protocol IN ('push', 'pull', 'file')) — docs/design/07-constraints.md.
 *
 * All three values exist in the schema; **only `File` has a writer in M5**
 * (decision 40). `Push` and `Pull` are a later phase, built against a core
 * this milestone proves with no network and no new dependency.
 */
enum TerminalProtocol: string
{
    use HasChoices;

    /** The device opens the connection and posts its records to khronoz. */
    case Push = 'push';

    /** khronoz opens the connection and reads from `stamp` forward. */
    case Pull = 'pull';

    /** Someone uploads the device's own attlog export. The only path M5 implements. */
    case File = 'file';

    public function label(): string
    {
        return match ($this) {
            self::Push => 'Device pushes',
            self::Pull => 'khronoz pulls',
            self::File => 'File import',
        };
    }
}
