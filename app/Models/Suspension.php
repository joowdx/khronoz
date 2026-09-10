<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Carbon\CarbonInterface;
use Database\Factories\SuspensionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Work suspended for a workgroup, or for the whole agency, on a date
 * (docs/design/05-calendar.md rules 2 and 3).
 *
 * `workgroup_id` null is agency-wide; a set one cascades to that workgroup's
 * descendants. Which employees that reaches is **not** "everyone deployed
 * under the workgroup" — it is everyone whose **operative** deployment on the
 * date sits in the subtree, the reassignment if one covers the date and
 * otherwise the substantive placement (decision 31). A closure declared on the
 * mother office must not excuse a day worked in the receiving one.
 *
 * Reads are plural, as with Holiday: a morning suspension and an afternoon one
 * on the same date are ordinary, and an agency-wide declaration may be
 * extended by a division for its own reason, so nothing here may `->first()`.
 *
 * `starts` and `ends` stay **strings** ('HH:MM:SS'), uncast. There is no
 * Eloquent `time` cast, and casting to a datetime would staple today's date
 * onto a clock time that belongs to `date` — which the deriver would then have
 * to strip off again. The whole schema already carries times as strings
 * (`shifts.slots`).
 */
#[Fillable(['agency_id', 'workgroup_id', 'date', 'starts', 'ends', 'reason', 'reference', 'user_id', 'declared_at'])]
class Suspension extends Model
{
    /** @use HasFactory<SuspensionFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'declared_at' => 'datetime',
        ];
    }

    /** Null for an agency-wide suspension. */
    public function workgroup(): BelongsTo
    {
        return $this->belongsTo(Workgroup::class);
    }

    /** Who declared it — possibly a platform superuser who entered the agency. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether the whole day is suspended, rather than a window of it. */
    public function wholeDay(): bool
    {
        return $this->starts === null;
    }

    /**
     * The employees this suspension reaches, as a query
     * (05-calendar.md rule 3).
     *
     * Written as the rule reads, in three clauses, rather than as one
     * DISTINCT ON — each clause is a sentence of decision 31 and the shape is
     * what stops it being "simplified" into the wrong thing:
     *
     *   - agency-wide (`workgroup_id` null): everyone deployed on the date.
     *     No subtree and no operative distinction, because there is nowhere
     *     for either to matter. A movement always nests inside its placement
     *     (`deployments_nested`), so "has any deployment covering the date"
     *     and "is employed on the date" are the same set.
     *   - otherwise, either the employee's **movement** covering the date is
     *     in the subtree,
     *   - or they have **no** movement covering the date and their
     *     substantive placement is.
     *
     * The negative clause is the whole point and the easiest thing to drop.
     * Without it, an employee detailed *out* of the suspended workgroup would
     * still be excused by their substantive placement — which is exactly the
     * "every employee deployed under the workgroup" reading rule 3 exists to
     * forbid, and it excuses a day the person actually worked elsewhere.
     *
     * The subtree is `Workgroup::descendants()`, which walks parent_id with a
     * recursive CTE using UNION so it terminates even over a cycle; the
     * workgroup itself is added here, since descendants() is strict.
     */
    public function appliesTo(): Builder
    {
        $date = $this->date;

        if ($this->workgroup_id === null) {
            return Employee::query()->whereHas('deployments', fn (Builder $any) => $any->covering($date));
        }

        $subtree = [
            $this->workgroup_id,
            ...$this->workgroup->descendants()->pluck('id')->all(),
        ];

        return Employee::query()->where(fn (Builder $operative) => $operative
            ->whereHas('deployments', fn (Builder $movement) => $movement
                ->whereNotNull('parent_id')->covering($date)->whereIn('workgroup_id', $subtree)
            )
            ->orWhere(fn (Builder $substantive) => $substantive
                ->whereDoesntHave('deployments', fn (Builder $movement) => $movement
                    ->whereNotNull('parent_id')->covering($date)
                )
                ->whereHas('deployments', fn (Builder $placement) => $placement
                    ->whereNull('parent_id')->covering($date)->whereIn('workgroup_id', $subtree)
                )
            )
        );
    }

    /** Every suspension declared for a date — plural, deliberately. */
    #[Scope]
    protected function covering(Builder $query, CarbonInterface $date): void
    {
        $query->whereDate('date', $date);
    }

    /** Every suspension from $from to $to inclusive — what a month view asks for. */
    #[Scope]
    protected function between(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
    }
}
