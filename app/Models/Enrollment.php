<?php

namespace App\Models;

use App\Enums\EnrollmentPrivilege;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\CoversDates;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which employee is which device user id on which terminal, and when
 * (docs/design/03-terminals.md).
 *
 * A **date range**, not a flag, and the whole point: `covering($date)` has at
 * most one answer because `enrollments_uid_one_person` forbids two rows of one
 * (terminal, uid) pair sharing a day. That is what lets `timelogs_resolve()`
 * be a plain SELECT with no ordering — and it is what the predecessor's
 * `UNIQUE (employee_id, scanner_id)` plus an `active` boolean could not
 * express, since a reissued uid could only be recorded by editing history.
 *
 * `uid` gets **no accessor and no mutator** (decision 42). The resolution
 * trigger joins on the raw column, so trimming or casting here would produce
 * rows that appear matched in the interface and never resolve in the database
 * — the exact defect the predecessor shipped.
 *
 * Nothing in the application writes a timelog's `employee_id`; creating or
 * moving one of these rows re-resolves the affected timelogs by trigger.
 */
#[Fillable(['agency_id', 'employee_id', 'terminal_id', 'uid', 'privilege', 'starts', 'ends'])]
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use BelongsToAgency, CoversDates, HasFactory, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'privilege' => EnrollmentPrivilege::class,
            'starts' => 'date',
            'ends' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }
}
