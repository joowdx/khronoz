<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\ScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A repeating cycle of shifts (docs/design/04-scheduling.md): `length` days,
 * one turn per day, resolved as `position = (D - roster.anchor) mod length`.
 *
 * The turns must be complete — exactly `length` rows at positions 0 to
 * `length - 1` — which `turns_complete` enforces as the schema's only
 * DEFERRABLE INITIALLY DEFERRED constraint, because the rule is transiently
 * false while a schedule and its turns are being written. Write both in one
 * transaction.
 */
#[Fillable(['agency_id', 'name', 'length', 'fallback_shift_id', 'origin_id'])]
class Schedule extends Model
{
    /** @use HasFactory<ScheduleFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    public function turns(): HasMany
    {
        return $this->hasMany(Turn::class)->orderBy('position');
    }

    /**
     * The shift the other turns of an ISO week revert to when a holiday or
     * suspension lands on an Off turn (04-scheduling.md rule 5, Res. 2600838
     * §2.3). Null means no revert: the week stands as written.
     */
    public function fallbackShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'fallback_shift_id');
    }

    /** The platform-owned schedule this one was copied from; null if it is not a copy. */
    public function origin(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origin_id');
    }

    public function rosters(): HasMany
    {
        return $this->hasMany(Roster::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }
}
