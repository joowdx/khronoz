<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Matches the `Rendition` interface in resources/js/types/index.d.ts.
 * @mixin \App\Models\Rendition
 */
class RenditionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'revision' => $this->revision, 'template' => $this->template,
            'status' => $this->status->value, 'token' => $this->token,
            'verification_url' => route('ledgers.verify', $this->token),
            'requested_at' => $this->requested_at?->toDateTimeString(),
            'generated_at' => $this->generated_at?->toDateTimeString(),
            'failed_at' => $this->failed_at?->toDateTimeString(),
            'superseded_at' => $this->superseded_at?->toDateTimeString(),
            'document' => $this->whenLoaded('document', fn ($document) => DocumentResource::make($document)->resolve()),
        ];
    }
}
