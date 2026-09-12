<?php

namespace App\Actions;

use App\Attendance\LedgerSnapshot;
use App\Enums\RenditionStatus;
use App\Jobs\GenerateLedgerDocument;
use App\Models\Attestation;
use App\Models\Ledger;
use App\Models\Rendition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AttestLedger
{
    public function __construct(private LedgerSnapshot $snapshots) {}

    public function handle(Ledger $ledger, User $actor, ?string $role = null): Attestation
    {
        abort_unless($actor->agency_id === $ledger->agency_id, 403);

        return DB::transaction(function () use ($ledger, $actor, $role): Attestation {
            $ledger = Ledger::query()->lockForUpdate()->findOrFail($ledger->id);
            if (! $ledger->locked()) {
                throw ValidationException::withMessages(['ledger' => 'Only a locked ledger may be attested.']);
            }
            $sequence = $ledger->attestations()->whereNull('withdrawn_at')->count() + 1;
            $next = $ledger->signers[$sequence - 1] ?? null;
            if ($next === null || ($role !== null && $role !== $next['role'])) {
                throw ValidationException::withMessages(['role' => 'Attest only the next required role.']);
            }
            abort_unless(in_array($actor->id, $next['user_ids'], true), 403);
            $attestation = $ledger->attestations()->create(['agency_id' => $ledger->agency_id, 'role' => $next['role'], 'sequence' => $sequence, 'user_id' => $actor->id, 'name' => $actor->name, 'at' => now()]);
            if ($sequence === count($ledger->policy['roles'])) {
                $archive = ($ledger->agency->settings['ledger_archiving'] ?? false) === true;
                $rendition = new Rendition([
                    'agency_id' => $ledger->agency_id,
                    'ledger_id' => $ledger->id,
                    'revision' => ($ledger->renditions()->max('revision') ?? 0) + 1,
                    'template' => $ledger->policy['template'],
                    'token' => Str::random(64),
                    'status' => $archive ? RenditionStatus::Pending : RenditionStatus::Unstored,
                    'requested_at' => $archive ? now() : null,
                ]);
                $rendition->id = (string) Str::ulid();
                $rendition->snapshot = [...$this->snapshots->forRendition($ledger), 'rendition' => ['id' => $rendition->id, 'revision' => $rendition->revision, 'token' => $rendition->token, 'completed_at' => $attestation->at->toIso8601String(), 'archiving' => $archive]];
                $rendition->save();
                if ($archive) {
                    GenerateLedgerDocument::dispatch($ledger->agency_id, $rendition->id)->afterCommit();
                }
            }

            return $attestation;
        });
    }
}
