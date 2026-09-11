<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

/**
 * Mirrors syncs.status (varchar) and the syncs_status_valid CHECK
 * (status IN ('running', 'completed', 'failed')) —
 * docs/design/07-constraints.md.
 *
 * Three values and not four: a run that read a file containing bad rows is
 * **completed**, not partial. Whether anything was rejected is `rejected > 0`,
 * which is a number the reader can act on, rather than a status word that
 * flattens "one malformed line" and "nothing parsed at all" into one bucket.
 * `Failed` means the run itself could not finish — an unreadable file, a
 * device that stopped answering — and `error` says why.
 */
enum SyncStatus: string
{
    use HasChoices;

    /** Opened, not yet closed. A run left here is one that died mid-flight. */
    case Running = 'running';

    /** Finished. The counters are final, however many were rejected. */
    case Completed = 'completed';

    /** Could not finish; `error` carries the reason. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }
}
