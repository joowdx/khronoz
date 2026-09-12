<?php

namespace App\Models;

use App\Enums\RenditionStatus;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\RenditionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agency_id', 'ledger_id', 'revision', 'template', 'snapshot', 'token', 'status', 'document_id', 'requested_at', 'generated_at', 'failed_at', 'superseded_at', 'error'])]
class Rendition extends Model
{
    /** @use HasFactory<RenditionFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'snapshot' => 'array',
            'status' => RenditionStatus::class,
            'requested_at' => 'immutable_datetime',
            'generated_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
