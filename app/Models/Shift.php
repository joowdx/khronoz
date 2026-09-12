<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['agency_id', 'name', 'slots', 'required', 'flex', 'remote', 'trust', 'color', 'origin_id'])]
class Shift extends Model
{
    /**
     * @use HasFactory<ShiftFactory>
     */
    use BelongsToAgency, HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slots' => 'array',
            'remote' => 'boolean',
            'trust' => 'boolean',
        ];
    }

    /**
     * The platform-owned shift this one was copied from; null if it is not a copy.
     */
    public function origin(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origin_id');
    }

    public function turns(): HasMany
    {
        return $this->hasMany(Turn::class);
    }
}
