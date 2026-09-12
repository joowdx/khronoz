<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\CoversDates;
use Database\Factories\RosterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agency_id', 'employee_id', 'schedule_id', 'team_id', 'anchor', 'starts', 'ends'])]
class Roster extends Model
{
    /**
     * @use HasFactory<RosterFactory>
     */
    use BelongsToAgency, CoversDates, HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'anchor' => 'date',
            'starts' => 'date',
            'ends' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /**
     * The cohort this assignment came from; null for an ad-hoc set.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
