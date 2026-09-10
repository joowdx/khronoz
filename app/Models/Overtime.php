<?php

namespace App\Models;

use App\Enums\OvertimeMode;
use App\Models\Concerns\BelongsToAgency;
use Carbon\CarbonInterface;
use Database\Factories\OvertimeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An authority to work beyond the shift (docs/design/05-calendar.md rules 4
 * and 6). Without one, work outside the shift is recorded as excess and never
 * compensable.
 *
 * `starts` and `ends` are **timestamps**, so an overnight authority is one row.
 * `date` is generated from `starts::date` and is therefore **not fillable** —
 * Postgres refuses any value for a generated column — and it is absent from a
 * freshly inserted model's attributes, which under Model::shouldBeStrict()
 * throws MissingAttributeException rather than answering null. The factory
 * refreshes after creating for that reason; a hand-built row must `refresh()`
 * before reading it.
 *
 * Two questions about "this date" that are **not** the same, which is why
 * neither scope is called `covering()`. `startingOn()` is the authority filed
 * for a calendar day, matching the generated column. `overlapping()` is the
 * authority whose window touches an interval — and an overnight row filed on
 * the 1st is what authorises 00:30 on the 2nd, so the deriver wants the second
 * question and would silently lose those minutes asking the first.
 */
#[Fillable(['agency_id', 'employee_id', 'starts', 'ends', 'purpose', 'mode', 'reference', 'user_id'])]
class Overtime extends Model
{
    /** @use HasFactory<OvertimeFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts' => 'datetime',
            'ends' => 'datetime',
            'date' => 'date',
            'mode' => OvertimeMode::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Who approved it — possibly a platform superuser who entered the agency. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Authorised minutes, from the two instants rather than from `date`. */
    public function minutes(): int
    {
        return (int) $this->starts->diffInMinutes($this->ends);
    }

    /**
     * Authorities **filed for** a calendar day, read off the generated column.
     * An overnight authority appears on the day it began and not on the day it
     * ended — see overlapping() for the other question.
     */
    #[Scope]
    protected function startingOn(Builder $query, CarbonInterface $date): void
    {
        $query->whereDate('date', $date);
    }

    /**
     * Authorities whose window touches $from..$to, as instants. What the
     * deriver needs for one calendar day: an authority filed on the 1st for
     * 22:00–02:00 authorises minutes on the 2nd, and startingOn() would not
     * find it.
     *
     * Half-open on the upper side to match overtimes_no_overlap's `[)`
     * tsrange: an authority that ends exactly at $from does not touch it.
     */
    #[Scope]
    protected function overlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->where('starts', '<', $to)->where('ends', '>', $from);
    }
}
