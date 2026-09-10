<?php

namespace App\Models;

use App\Enums\Sex;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

/**
 * A person an agency keeps a DTR for (docs/design/01-organization.md).
 *
 * Soft-deleted, not hard-deleted (R6): $employee->delete() is an UPDATE, so it
 * never trips the ON DELETE RESTRICT that workgroups.head_id and
 * deployments.employee_id carry against this table. A hard delete of a
 * referenced employee is still refused by the database; Task 4 tests that
 * side directly with a real DELETE.
 */
#[Fillable([
    'agency_id', 'number', 'first_name', 'middle_name', 'last_name', 'suffix',
    'sex', 'birthdate', 'email', 'mobile', 'position', 'tags', 'exempt',
])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use BelongsToAgency, HasFactory, HasUlids, Searchable, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'sex' => Sex::class,
            'exempt' => 'boolean',
            'birthdate' => 'date',
        ];
    }

    /**
     * Every key here is a column a search term is matched against, so an
     * identifier in this array is a term that matches every row.
     * `DatabaseEngine` builds its query from `array_keys(toSearchableArray())`
     * and, on pgsql, adds one `orWhere(column, 'ilike', '%term%')` per key
     * (vendor/laravel/scout/src/Engines/DatabaseEngine.php:221, 262-305);
     * its `canSearchPrimaryKey` shortcut needs an integer key, so a ULID `id`
     * is just another ilike column. MEASURED with `id` in the array, on the
     * seeded Demo Agency: `q` matched 32 of 32 employees, `n2d7` 32 of 32,
     * `a` 32 of 32 — 26 characters of ULID per row means the first keystroke
     * of a search reliably fails to narrow, on the primary way a timekeeper
     * finds a person. `id` is gone for that reason and nothing needs it back:
     * an external engine takes the document key from `getScoutKey()`, merged
     * in by the engine itself (MeilisearchEngine::update() line 87, and
     * Algolia's `objectID`), never from this array.
     *
     * `agency_id` is R10's other half and stays for the engines that need
     * it: Algolia, Meilisearch and Typesense match their own index and
     * bypass every Eloquent global scope, so the id has to be *in* that index
     * for a call site's `->where('agency_id', …)` to filter on it. Under the
     * shipped `database` driver nothing filters through the index —
     * `DatabaseEngine::newSearchQuery()` falls back to `Model::newQuery()`,
     * which carries AgencyScope, and EmployeeController::index's explicit
     * filter is a plain SQL condition either way — so including it there
     * would only widen every ilike group by another 26-character identifier,
     * for no protection at all. Either way every ::search() call site must
     * filter by agency_id explicitly (.ai/rules/models.md).
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            // Indexed only where an index is what gets filtered — see above.
            ...(config('scout.driver') === 'database' ? [] : ['agency_id' => $this->agency_id]),
            'number' => $this->number,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'position' => $this->position,
        ];
    }

    /** First, middle, last, suffix, composed; blank parts collapse instead of leaving doubled spaces. */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn (): string => collect([$this->first_name, $this->middle_name, $this->last_name, $this->suffix])
                ->filter()
                ->implode(' '),
        );
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /**
     * The *substantive* placement covering today — where the plantilla item
     * sits right now.
     *
     * Two halves of the predicate, each load-bearing for its own reason.
     *
     * `parent_id IS NULL` because a reassigned employee has two rows covering
     * the same day since decision 31, so "the deployment" is otherwise
     * ambiguous and this hasOne would return whichever the database handed
     * back first. Decision 31's own table is emphatic that the three
     * consumers of the nesting resolve it differently and must not be
     * collapsed into one "operative placement" helper — this relation is the
     * *substantive* answer, which is what `head`, a transfer and a removal
     * each want. A work suspension wants the operative row instead
     * (05-calendar.md rule 3, Milestone 5) and gets its own accessor then.
     *
     * Covering **today**, rather than the older "`ends IS NULL`". A
     * placement may be fixed-term — contractual, casual, co-terminous — so a
     * row that is perfectly current can already carry an `ends`, and asking
     * for the open one would have reported no workgroup at all for those
     * people. Note what cannot be substituted: "has not ended yet"
     * (`ends IS NULL OR ends >= today`) is *not* at most one, because a
     * placement ending in June and its successor starting in July both
     * satisfy it. Covering a single date is at most one, and
     * deployments_no_overlap is what guarantees that — this hasOne is only
     * sound with the date bound on both sides.
     *
     * A row closed on today is still current today; `ends` is inclusive.
     */
    public function currentDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)->whereNull('parent_id')->coveringToday();
    }

    /**
     * The reassignment covering today, if the person is detailed elsewhere
     * right now. At most one, by deployments_no_overlapping_movements —
     * nobody is detailed to two places at once — and date-bounded for the
     * same reason currentDeployment is.
     */
    public function currentReassignment(): HasOne
    {
        return $this->hasOne(Deployment::class)->whereNotNull('parent_id')->coveringToday();
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
