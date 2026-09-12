<?php

namespace App\Models;

use App\Attendance\CadenceRange;
use App\Enums\CadenceKind;
use App\Models\Concerns\BelongsToAgency;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\CadenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['agency_id', 'name', 'kind', 'rules', 'anchor', 'preferred', 'retired_at'])]
class Cadence extends Model
{
    /** @use HasFactory<CadenceFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'kind' => CadenceKind::class,
            'rules' => 'array',
            'anchor' => 'immutable_date',
            'preferred' => 'boolean',
            'retired_at' => 'immutable_datetime',
        ];
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    public function bounds(CarbonInterface $date): array
    {
        return CadenceRange::containing($this->kind, $date, $this->anchor, $this->rules);
    }
}
