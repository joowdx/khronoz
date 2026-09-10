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

/**
 * A day template (docs/design/04-scheduling.md): the expected in/out pairs,
 * what a complete day credits, and how far the arrival may slide.
 *
 * Two shifts have no slots and are told apart by `remote`: `Off` expects
 * nothing and credits nothing, while `Remote` expects no punches but is
 * credited on attestation (Flexiplace, OP MC 114). Neither reads `color`,
 * since the roster grid draws them from the empty slots and the flag.
 *
 * Unlike most tenant models a shift may belong to the platform agency — that
 * is where the defaults live — so `origin_id` points at the platform row a
 * copy came from and is the one deliberate cross-agency pointer in the
 * schema. It is reference-only: `origin_is_platform` proves it names a
 * platform row, and nothing resolves through it.
 */
#[Fillable(['agency_id', 'name', 'slots', 'required', 'flex', 'remote', 'trust', 'color', 'origin_id'])]
class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'slots' => 'array',
            'remote' => 'boolean',
            'trust' => 'boolean',
        ];
    }

    /** The platform-owned shift this one was copied from; null if it is not a copy. */
    public function origin(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origin_id');
    }

    public function turns(): HasMany
    {
        return $this->hasMany(Turn::class);
    }
}
