<?php

namespace App\Models;

use App\Enums\Premium;
use App\Enums\WorkdayStatus;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\WorkdayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'agency_id', 'ledger_id', 'employee_id', 'date', 'shift_id', 'shift',
    'exemption_id', 'status', 'premium', 'worked', 'credited', 'tardy',
    'undertime', 'excess', 'night', 'night_excess', 'computed_at',
])]
class Workday extends Model
{
    /**
     * @use HasFactory<WorkdayFactory>
     */
    use BelongsToAgency, HasFactory, HasUlids;

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'month' => 'date',
            'shift' => 'array',
            'status' => WorkdayStatus::class,
            'premium' => Premium::class,
            'worked' => 'integer',
            'credited' => 'integer',
            'tardy' => 'integer',
            'undertime' => 'integer',
            'excess' => 'integer',
            'night' => 'integer',
            'night_excess' => 'integer',
            'computed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The shift row this snapshot was resolved from. Not `shift()`: that name
     * would shadow the json column, which is the thing every reader wants.
     */
    public function resolvedShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function exemption(): BelongsTo
    {
        return $this->belongsTo(Exemption::class);
    }

    public function punches(): HasMany
    {
        return $this->hasMany(Punch::class);
    }
}
