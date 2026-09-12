<?php

namespace App\Models;

use App\Enums\EnrollmentPrivilege;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\CoversDates;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agency_id', 'employee_id', 'terminal_id', 'uid', 'privilege', 'starts', 'ends'])]
class Enrollment extends Model
{
    /**
     * @use HasFactory<EnrollmentFactory>
     */
    use BelongsToAgency, CoversDates, HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'privilege' => EnrollmentPrivilege::class,
            'starts' => 'date',
            'ends' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }
}
