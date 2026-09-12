<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agency_id', 'document_id', 'store', 'key', 'verified_at', 'primary', 'retired_at'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'verified_at' => 'immutable_datetime',
            'primary' => 'boolean',
            'retired_at' => 'immutable_datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
