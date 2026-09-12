<?php

namespace App\Actions;

use App\Models\Acceptance;
use App\Models\User;
use App\Support\Legal;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordAcceptance
{
    public function __construct(private Legal $legal, private Tenant $tenant) {}

    /** @param array<string, array{version: string, hash: string, accepted: mixed}> $documents */
    public function handle(User $user, array $documents): void
    {
        $current = $this->legal->current();

        foreach ($current as $document) {
            $submitted = $documents[$document['slug']] ?? [];

            if (($submitted['version'] ?? null) !== $document['version'] || ($submitted['hash'] ?? null) !== $document['hash']) {
                throw ValidationException::withMessages(['form' => 'The documents changed. Reload this page and review the current versions.']);
            }
        }

        DB::transaction(function () use ($user, $current): void {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);

            // Account acknowledgments belong to the user's agency, even during platform impersonation.
            $this->tenant->within($owner->agency, function () use ($owner, $current): void {
                foreach ($current as $document) {
                    $acceptance = Acceptance::firstOrCreate([
                        'user_id' => $owner->id,
                        'document' => $document['slug'],
                        'version' => $document['version'],
                    ], [
                        'agency_id' => $owner->agency_id,
                        'content_hash' => $document['hash'],
                        'accepted_at' => now(),
                    ]);

                    if ($acceptance->content_hash !== $document['hash']) {
                        throw ValidationException::withMessages(['form' => 'This document version has changed. Contact the operator to publish a new version.']);
                    }
                }
            });
        });
    }
}
