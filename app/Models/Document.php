<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['agency_id', 'name', 'mime', 'bytes', 'algorithm', 'digest'])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
        ];
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
