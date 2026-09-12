<?php

namespace App\Models;

use App\Enums\TerminalKind;
use App\Enums\TerminalProtocol;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\TerminalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'agency_id', 'workgroup_id', 'code', 'name', 'serial', 'kind', 'protocol',
    'host', 'port', 'secret', 'drift', 'meta', 'seen_at', 'synced_at', 'stamp', 'active',
])]
class Terminal extends Model
{
    /**
     * @use HasFactory<TerminalFactory>
     */
    use BelongsToAgency, HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TerminalKind::class,
            'protocol' => TerminalProtocol::class,
            'port' => 'integer',
            'secret' => 'encrypted',
            'drift' => 'integer',
            'meta' => 'array',
            'seen_at' => 'datetime',
            'synced_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    /**
     * Where the device sits; null means it serves the agency rather than one office.
     */
    public function workgroup(): BelongsTo
    {
        return $this->belongsTo(Workgroup::class);
    }

    /**
     * Who this device can identify, over time. `covering($date)` picks the one that answers for a date.
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * Every punch it has ever captured. Immutable, and never deleted (decision 41).
     */
    public function timelogs(): HasMany
    {
        return $this->hasMany(Timelog::class);
    }

    /**
     * Every ingestion run against this device, successful or not.
     */
    public function syncs(): HasMany
    {
        return $this->hasMany(Sync::class);
    }

    /** Terminals still in service. `active` is a switch, not a delete — the punches stay. */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('active', true);
    }
}
