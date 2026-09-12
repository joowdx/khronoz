<?php

namespace App\Models\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

trait CoversDates
{
    #[Scope]
    protected function covering(Builder $query, CarbonInterface $date): void
    {
        $query->where('starts', '<=', $date)
            ->where(fn (Builder $ended) => $ended->whereNull('ends')->orWhere('ends', '>=', $date));
    }

    #[Scope]
    protected function coveringToday(Builder $query): void
    {
        $query->covering(today());
    }
}
