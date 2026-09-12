<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\WorkgroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['agency_id', 'parent_id', 'kind', 'code', 'name', 'head_id'])]
class Workgroup extends Model
{
    /**
     * @use HasFactory<WorkgroupFactory>
     */
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

    public function descendants(): Builder
    {
        return static::query()->whereRaw(
            <<<'SQL'
            workgroups.id IN (
                WITH RECURSIVE descendants (id) AS (
                    SELECT workgroups.id FROM workgroups WHERE workgroups.parent_id = ?
                    UNION
                    SELECT workgroups.id FROM workgroups JOIN descendants ON workgroups.parent_id = descendants.id
                )
                SELECT id FROM descendants
            )
            SQL,
            [$this->id]
        );
    }

    /**
     * Every workgroup strictly above this one, up to the root. Same shape as
     * workgroups_acyclic()'s own upward walk (starts at the parent, follows
     * parent_id up), including the UNION for the same reason.
     */
    public function ancestors(): Builder
    {
        return static::query()->whereRaw(
            <<<'SQL'
            workgroups.id IN (
                WITH RECURSIVE ancestry (id, parent_id) AS (
                    SELECT workgroups.id, workgroups.parent_id FROM workgroups WHERE workgroups.id = ?
                    UNION
                    SELECT workgroups.id, workgroups.parent_id FROM workgroups JOIN ancestry ON workgroups.id = ancestry.parent_id
                )
                SELECT id FROM ancestry
            )
            SQL,
            [$this->parent_id]
        );
    }
}
