<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\AttestationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agency_id', 'ledger_id', 'role', 'sequence', 'user_id', 'name', 'at', 'withdrawn_by', 'withdrawn_at'])]
class Attestation extends Model
{
    /**
     * @use HasFactory<AttestationFactory>
     */
    use BelongsToAgency, HasFactory, HasUlids;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function withdrawer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }
}
