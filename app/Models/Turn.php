<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\TurnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agency_id', 'schedule_id', 'shift_id', 'position'])]
class Turn extends Model
{
    /**
     * @use HasFactory<TurnFactory>
     */
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
