<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Carbon\CarbonInterface;
use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An employee's placement in a workgroup over a date range
 * (docs/design/01-organization.md rules 2 and 7): the history of where a
 * person has worked.
 *
 * `parent_id` null means this row is the employee's *substantive* placement,
 * where the plantilla item sits; `parent_id` set means it is a
 * *reassignment* — the person works elsewhere for a period while the
 * substantive placement stays open, because the item never left. There is no
 * type column: `parent_id IS NOT NULL` is the whole fact (decision 31).
 *
 * At most one open row *of each class* per employee, enforced by the two
 * partial exclusion constraints deployments_no_overlap and
 * deployments_no_overlapping_movements, not by application code. A
 * reassignment may overlap the placement it departs from and never another
 * reassignment.
 *
 * **This model must never use SoftDeletes** (decision 35). Correcting a
 * wrongly recorded deployment is a delete and re-create, because the row was
 * never true and principle 2's "a change is a new range" does not apply to a
 * mistake. A soft delete is an UPDATE: the row would keep its `starts` and
 * `ends` and go on occupying the timeline those two exclusion constraints
 * index, so the corrected row replacing it would be refused with 23P01 by the
 * very row it corrects.
 */
#[Fillable(['agency_id', 'employee_id', 'workgroup_id', 'parent_id', 'starts', 'ends'])]
class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'starts' => 'date',
            'ends' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function workgroup(): BelongsTo
    {
        return $this->belongsTo(Workgroup::class);
    }

    /**
     * Rows whose range covers $date — at most one per class, since
     * deployments_no_overlap and deployments_no_overlapping_movements each
     * forbid two rows of their own class sharing a day. `ends` is inclusive
     * (the ranges are built '[]'), so a row closed on $date still covers it.
     *
     * A scope rather than three inline predicates, because getting it wrong
     * in one place is a silent correctness bug: "has not ended yet" looks
     * equivalent and is not at most one.
     */
    #[Scope]
    protected function covering(Builder $query, CarbonInterface $date): void
    {
        $query->where('starts', '<=', $date)
            ->where(fn (Builder $ended) => $ended->whereNull('ends')->orWhere('ends', '>=', $date));
    }

    /** `covering(today())`, the overwhelmingly common case. */
    #[Scope]
    protected function coveringToday(Builder $query): void
    {
        $query->covering(today());
    }

    /** The substantive placement this row is a reassignment from; null on a placement itself. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * The reassignments nested under this placement. Never more than one per
     * date, and each sits inside this row's own range — both enforced by
     * deployments_no_overlapping_movements and deployments_nested.
     */
    public function movements(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
