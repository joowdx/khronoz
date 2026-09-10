<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\CoversDates;
use Database\Factories\RosterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee follows a schedule from `starts` to `ends`, with the cycle
 * anchored at `anchor` (docs/design/04-scheduling.md). This is the **only**
 * assignment — a direct FK, no polymorphism — and an exception is just a
 * roster of its own.
 *
 * One roster per employee per date, by `rosters_no_overlap`, and that is what
 * makes a one-week override a one-week roster: the constraint forces the
 * standing roster to be ended first, which is the right paper trail (rule 3).
 *
 * `schedule_id` and `anchor` **may differ** from those of the team `team_id`
 * names, and nothing forbids it. `team_id` records where the assignment came
 * from, not a rule about what it produced, so an agency can slide one nurse's
 * anchor by a day without taking her off the cohort. Resolution reads this
 * row and never the team.
 */
#[Fillable(['agency_id', 'employee_id', 'schedule_id', 'team_id', 'anchor', 'starts', 'ends'])]
class Roster extends Model
{
    /** @use HasFactory<RosterFactory> */
    use BelongsToAgency, CoversDates, HasFactory, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'anchor' => 'date',
            'starts' => 'date',
            'ends' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /** The cohort this assignment came from; null for an ad-hoc set. */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
