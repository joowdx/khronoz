<?php

namespace App\Models;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\SyncFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'agency_id', 'terminal_id', 'trigger', 'status', 'started_at', 'finished_at',
    'drift', 'received', 'accepted', 'duplicates', 'rejected', 'reference',
    'earliest', 'latest', 'error',
])]
class Sync extends Model
{
    /**
     * @use HasFactory<SyncFactory>
     */
    use BelongsToAgency, HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => SyncTrigger::class,
            'status' => SyncStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'earliest' => 'datetime',
            'latest' => 'datetime',
            'drift' => 'integer',
            'received' => 'integer',
            'accepted' => 'integer',
            'duplicates' => 'integer',
            'rejected' => 'integer',
        ];
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /**
     * The rows this run inserted. Only `accepted` rows are here; duplicates were skipped.
     */
    public function timelogs(): HasMany
    {
        return $this->hasMany(Timelog::class);
    }
}
