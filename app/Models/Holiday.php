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

/**
 * A date on which no work is expected, or on which work is paid at a premium
 * (docs/design/05-calendar.md rule 1).
 *
 * **A date may carry more than one holiday, and both are owed.** Eid al-Fitr
 * can land on Bonifacio Day and a city charter day on a national special day;
 * DOLE's rule (dole-rules.md section I item 6) applies the higher rate and the
 * day may attract both premiums. `holidays_agency_id_date_name_unique` puts
 * `name` in the key precisely so the second row is accepted.
 *
 * Every read of this table is therefore **plural**. `covering()` returns a
 * query and there is deliberately no `firstOnDate()` helper to reach for: a
 * `->first()` here compiles, passes a naive test, and silently discards the
 * more expensive holiday.
 *
 * This is the one model that reads two agencies — its own and the platform
 * row, where national holidays live — via AgencyOrPlatformScope.
 */
#[Fillable(['agency_id', 'date', 'name', 'type', 'reference', 'declared_at'])]
class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => HolidayType::class,
            'declared_at' => 'datetime',
        ];
    }

    /**
     * Owned by the platform agency, and so owed by every tenant.
     *
     * The predicate lives here because two places need it and they are not
     * near each other: `HolidayPolicy` refuses an agency editing a national
     * row, and `HolidayController` fans a recompute out to every agency for
     * one (decision 86). Written twice it is written differently once.
     */
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
     * Every holiday on a date — plural, and named for the range idiom rather
     * than for a lookup, so the call site reads as a set. Two rows on one date
     * is the normal case this milestone exists to preserve.
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
