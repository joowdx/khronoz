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

#[Fillable(['agency_id', 'workgroup_id', 'date', 'starts', 'ends', 'reason', 'reference', 'user_id', 'declared_at'])]
class Suspension extends Model
{
    /**
     * @use HasFactory<SuspensionFactory>
     */
    use BelongsToAgency, HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'declared_at' => 'datetime',
        ];
    }

    public function workgroup(): BelongsTo
    {
        return $this->belongsTo(Workgroup::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wholeDay(): bool
    {
        return $this->starts === null;
    }

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
