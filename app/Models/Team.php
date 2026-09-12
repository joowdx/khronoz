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

#[Fillable(['agency_id', 'name', 'schedule_id', 'anchor'])]
class Team extends Model
{
    /**
     * @use HasFactory<TeamFactory>
     */
    use BelongsToAgency, HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['anchor' => 'date'];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function rosters(): HasMany
    {
        return $this->hasMany(Roster::class);
    }
}
