<?php

namespace App\Models;

use App\Enums\TimelogSource;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\TimelogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'agency_id', 'terminal_id', 'sync_id', 'uid', 'time', 'state', 'mode',
    'source', 'user_id', 'voided_at', 'reason', 'voided_by',
])]
class Timelog extends Model
{
    /**
     * @use HasFactory<TimelogFactory>
     */
    use BelongsToAgency, HasFactory, HasUlids;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'time' => 'datetime',
            'state' => 'integer',
            'mode' => 'integer',
            'source' => TimelogSource::class,
            'voided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function sync(): BelongsTo
    {
        return $this->belongsTo(Sync::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * Who entered it, manual rows only.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function void(string $reason, User $by): bool
    {
        return $this->update(['voided_at' => now(), 'reason' => $reason, 'voided_by' => $by->id]);
    }

    /**
     * Who struck this record out, if anybody has.
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** Rows still standing. A voided row stays readable; it is simply not counted. */
    #[Scope]
    protected function standing(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    /** Rows the database could not attribute to anybody. Visible on purpose. */
    #[Scope]
    protected function unresolved(Builder $query): void
    {
        $query->whereNull('employee_id');
    }
}
