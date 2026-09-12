<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\RenditionStatus;
use App\Jobs\GenerateLedgerDocument;
use App\Models\Rendition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RetryLedgerDocument
{
    public function handle(Rendition $rendition, User $actor): Rendition
    {
        Gate::forUser($actor)->authorize(Permission::ManageLedgers->value);

        return DB::transaction(function () use ($rendition): Rendition {
            $rendition = Rendition::query()->lockForUpdate()->findOrFail($rendition->id);
            if ($rendition->status !== RenditionStatus::Failed) {
                throw ValidationException::withMessages(['rendition' => 'Only a failed archive may be retried.']);
            }
            $rendition->update(['status' => RenditionStatus::Pending, 'failed_at' => null, 'error' => null]);
            GenerateLedgerDocument::dispatch($rendition->agency_id, $rendition->id)->afterCommit();

            return $rendition;
        });
    }
}
