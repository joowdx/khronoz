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
 * never trips the ON DELETE RESTRICT that units.head_id and
 * deployments.employee_id carry against this table. A hard delete of a
 * referenced employee is still refused by the database; Task 4 tests that
 * side directly with a real DELETE.
 */
#[Fillable([
    'agency_id', 'number', 'first_name', 'middle_name', 'last_name', 'suffix',
    'sex', 'birthdate', 'email', 'mobile', 'position', 'tags', 'exempt',
    'hired_at', 'separated_at',
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
            'hired_at' => 'date',
            'separated_at' => 'date',
        ];
    }

    /**
     * agency_id must be here (R10): SCOUT_DRIVER=database happens to route
     * search() through Model::newQuery(), which still carries AgencyScope —
     * verified empirically, see task-3-report.md — but that is one engine's
     * implementation detail, not a Scout guarantee. Algolia, Meilisearch and
     * Typesense all return ids straight from their own index and bypass every
     * Eloquent global scope entirely. Every ::search() call site must filter
     * by agency_id explicitly regardless of driver (.ai/rules/models.md).
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'agency_id' => $this->agency_id,
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

    /** The one open placement (ends IS NULL). At most one, by deployments_no_overlap. */
    public function currentDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)->whereNull('ends');
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
