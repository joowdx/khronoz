<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\TurnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of a schedule's cycle: which shift sits at which position
 * (docs/design/04-scheduling.md). A 21-day hospital rotation is 21 rows.
 *
 * Three constraints share the work of "the cycle is complete":
 * UNIQUE (schedule_id, position) forbids duplicates, a CHECK forbids
 * negatives, and only then does `turns_complete` have to prove the count and
 * the maximum to force exactly the set 0 to length - 1.
 */
#[Fillable(['agency_id', 'schedule_id', 'shift_id', 'position'])]
class Turn extends Model
{
    /** @use HasFactory<TurnFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
