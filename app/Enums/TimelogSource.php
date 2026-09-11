<?php

namespace App\Enums;

/**
 * Mirrors timelogs.source (varchar) and the timelogs_source_valid CHECK
 * (source IN ('device', 'manual')) — docs/design/07-constraints.md.
 *
 * Where the record came from, and the two CHECKs that hang off it are what
 * make the distinction real rather than advisory: a `Device` row must name the
 * `sync_id` that brought it in, and a `Manual` row must name the `user_id` who
 * entered it (MC 21 s. 1991 — who recorded it). Neither can borrow the other's
 * provenance.
 */
enum TimelogSource: string
{
    /** Captured by the terminal and carried in by a sync. */
    case Device = 'device';

    /** Entered by a person, because the device missed it. Requires a user. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Device => 'Device',
            self::Manual => 'Entered manually',
        };
    }
}
