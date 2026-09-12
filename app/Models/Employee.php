<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Carbon\CarbonInterface;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

#[Fillable([
    'agency_id', 'number', 'first_name', 'middle_name', 'last_name', 'suffix',
    'position', 'tags', 'exempt', 'cadence_id',
])]
class Employee extends Model
{
    /**
     * @use HasFactory<EmployeeFactory>
     */
    use BelongsToAgency, HasFactory, HasUlids, Searchable, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'exempt' => 'boolean',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [

            ...(config('scout.driver') === 'database' ? [] : ['agency_id' => $this->agency_id]),
            'number' => $this->number,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,
            'position' => $this->position,
        ];
    }

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

    public function cadence(): BelongsTo
    {
        return $this->belongsTo(Cadence::class);
    }

    public function currentDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)->whereNull('parent_id')->coveringToday();
    }

    public function currentReassignment(): HasOne
    {
        return $this->hasOne(Deployment::class)->whereNotNull('parent_id')->coveringToday();
    }

    public function operativeDeployment(CarbonInterface $date): ?Deployment
    {
        return $this->deployments()
            ->covering($date)
            ->orderByRaw('deployments.parent_id IS NULL')
            ->first();
    }

    public function rosters(): HasMany
    {
        return $this->hasMany(Roster::class);
    }

    /**
     * The one roster covering today — what this person is expected to work
     * right now. At most one, by rosters_no_overlap, and date-bounded on both
     * sides for the reason currentDeployment is.
     */
    public function currentRoster(): HasOne
    {
        return $this->hasOne(Roster::class)->coveringToday();
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
