<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's placement in a unit over a date range (docs/design/01-organization.md
 * rule 2): the history of where a person has worked, one row at a time. At
 * most one open row (ends IS NULL) per employee is enforced by the
 * deployments_no_overlap exclusion constraint, not by application code.
 */
#[Fillable(['agency_id', 'employee_id', 'unit_id', 'starts', 'ends'])]
class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'starts' => 'date',
            'ends' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
