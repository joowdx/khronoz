<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\AcceptanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agency_id', 'user_id', 'document', 'version', 'content_hash', 'accepted_at'])]
class Acceptance extends Model
{
    /** @use HasFactory<AcceptanceFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
