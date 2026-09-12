<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Matches the `LedgerDocument` interface in resources/js/types/index.d.ts.
 * @mixin \App\Models\Document
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'mime' => $this->mime,
            'bytes' => $this->bytes, 'algorithm' => $this->algorithm, 'digest' => $this->digest,
        ];
    }
}
