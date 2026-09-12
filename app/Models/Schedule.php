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

#[Fillable(['agency_id', 'name', 'length', 'fallback_shift_id', 'origin_id'])]
class Schedule extends Model
{
    /**
     * @use HasFactory<ScheduleFactory>
     */
    use BelongsToAgency, HasFactory, HasUlids;

    public function turns(): HasMany
    {
        return $this->hasMany(Turn::class)->orderBy('position');
    }

    public function fallbackShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'fallback_shift_id');
    }

    /**
     * The platform-owned schedule this one was copied from; null if it is not a copy.
     */
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
