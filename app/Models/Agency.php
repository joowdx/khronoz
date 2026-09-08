<?php

namespace App\Models;

use App\Models\Scopes\NotPlatformScope;
use Database\Factories\AgencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant. Exactly one row has `platform` true; it owns the shared rows
 * and the superusers, and every list hides it (NotPlatformScope).
 */
#[Fillable(['code', 'name', 'settings'])]
#[ScopedBy(NotPlatformScope::class)]
class Agency extends Model
{
    /** @use HasFactory<AgencyFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return ['platform' => 'boolean', 'settings' => 'array'];
    }

    /** The platform row, hidden from every other query. */
    public static function platform(): self
    {
        return static::withoutGlobalScope(NotPlatformScope::class)->where('platform', true)->firstOrFail();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
