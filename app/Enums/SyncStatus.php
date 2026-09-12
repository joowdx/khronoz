<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

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
