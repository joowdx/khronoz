<?php

namespace App\Enums;

/**
 * Mirrors terminals.kind (varchar) and the terminals_kind_valid CHECK
 * (kind IN ('terminal', 'usb')) — docs/design/07-constraints.md.
 *
 * This is **how the punches get here**, not what the hardware is. Both kinds
 * are biometric devices; they differ in whether khronoz can reach one over a
 * network or has to wait for someone to carry a file across.
 */
enum TerminalKind: string
{
    /** A networked device khronoz can reach — push, pull, or a file exported from it. */
    case Terminal = 'terminal';

    /** An offline device read by carrying its export across on a USB stick. */
    case Usb = 'usb';

    public function label(): string
    {
        return match ($this) {
            self::Terminal => 'Networked terminal',
            self::Usb => 'Offline device',
        };
    }
}
