<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\PolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agency_id', 'workgroup_id', 'employee_id', 'template', 'roles', 'supervisor', 'head_kind'])]
class Policy extends Model
{
    /** @use HasFactory<PolicyFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'roles' => 'array',
        ];
    }

    public function workgroup(): BelongsTo
    {
        return $this->belongsTo(Workgroup::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
