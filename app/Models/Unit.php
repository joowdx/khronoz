<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node in the agency's org tree (docs/design/01-organization.md).
 *
 * `kind` is deliberately a plain string with no CHECK and no PHP enum (R5):
 * 01-organization.md types it "label only", and a later milestone matches it
 * against a per-agency setting string, which a fixed platform-wide enum
 * would break.
 */
#[Fillable(['agency_id', 'parent_id', 'kind', 'code', 'name', 'head_id'])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'head_id');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /**
     * Every unit strictly under this one, any number of levels down: a
     * recursive CTE over parent_id, the documented way to answer "this unit
     * and everything under it" (01-organization.md rule 4).
     *
     * UNION, not UNION ALL, in the recursive term — mirroring units_acyclic()
     * in 0001_01_01_000007_prepare_organization.php — so the walk dedupes
     * and terminates in about a millisecond even over a cycle, instead of
     * running until cancelled. Cycles are already refused by the
     * units_acyclic constraint trigger; this is defence in depth, and it
     * costs nothing.
     */
    public function descendants(): Builder
    {
        return static::query()->whereRaw(
            <<<'SQL'
            units.id IN (
                WITH RECURSIVE descendants (id) AS (
                    SELECT units.id FROM units WHERE units.parent_id = ?
                    UNION
                    SELECT units.id FROM units JOIN descendants ON units.parent_id = descendants.id
                )
                SELECT id FROM descendants
            )
            SQL,
            [$this->id]
        );
    }

    /**
     * Every unit strictly above this one, up to the root. Same shape as
     * units_acyclic()'s own upward walk (starts at the parent, follows
     * parent_id up), including the UNION for the same reason.
     */
    public function ancestors(): Builder
    {
        return static::query()->whereRaw(
            <<<'SQL'
            units.id IN (
                WITH RECURSIVE ancestry (id, parent_id) AS (
                    SELECT units.id, units.parent_id FROM units WHERE units.id = ?
                    UNION
                    SELECT units.id, units.parent_id FROM units JOIN ancestry ON units.id = ancestry.parent_id
                )
                SELECT id FROM ancestry
            )
            SQL,
            [$this->parent_id]
        );
    }
}
