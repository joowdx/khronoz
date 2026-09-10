<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named (schedule, anchor) cohort (docs/design/04-scheduling.md). Three
 * hospital teams are one schedule and three anchors, sitting seven positions
 * apart so every shift is covered.
 *
 * **No membership table and no `members()` pivot to add**: the rosters
 * carrying this team's id *are* its membership, which is why `rosters()`
 * below is the only answer to "who is on this team". That shape makes "who
 * was on it in March" answerable from the rosters' own date ranges, and makes
 * one employee on two teams at once impossible without a second constraint,
 * since a roster already covers a date exclusively.
 *
 * A team is deliberately not a `Workgroup` (.ai/rules/models.md): a workgroup
 * is where a person is *placed*, one at a time, through a Deployment; a team
 * is the rotation they are *rostered into*. It also has no `starts`/`ends` —
 * a team is a standing definition, not an arrangement with a range.
 */
#[Fillable(['agency_id', 'name', 'schedule_id', 'anchor'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['anchor' => 'date'];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /** This team's membership. There is no pivot; see the class docblock. */
    public function rosters(): HasMany
    {
        return $this->hasMany(Roster::class);
    }
}
