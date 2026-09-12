<?php

namespace App\Models;

use App\Enums\HolidayType;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Scopes\AgencyOrPlatformScope;
use App\Tenancy\Tenant;
use Carbon\CarbonInterface;
use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope as EloquentScope;

#[Fillable(['agency_id', 'date', 'name', 'type', 'reference', 'declared_at'])]
class Holiday extends Model
{
    /**
     * @use HasFactory<HolidayFactory>
     */
    use BelongsToAgency, HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => HolidayType::class,
            'declared_at' => 'datetime',
        ];
    }

    public function national(): bool
    {
        return $this->agency_id === app(Tenant::class)->platformId();
    }

    /**
     * `agency_id IN (own, platform)` instead of `agency_id = own`, because a
     * national holiday is owned by the platform agency and applies to every
     * tenant. The only override of this in the schema.
     */
    protected static function agencyScope(): EloquentScope
    {
        return new AgencyOrPlatformScope;
    }

    /**
     * Every holiday on a date — plural, and named for the range idiom rather than for a lookup,
     * so the call site reads as a set. Two rows on one date is the normal case.
     */
    #[Scope]
    protected function covering(Builder $query, CarbonInterface $date): void
    {
        $query->whereDate('date', $date);
    }

    /** Every holiday from $from to $to inclusive — what a month view asks for. */
    #[Scope]
    protected function between(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
    }
}
