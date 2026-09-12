<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\RenditionStatus;
use App\Models\Rendition;
use App\Models\User;

class RenditionPolicy
{
    public function __construct(private LedgerPolicy $ledgers) {}

    public function view(User $user, Rendition $rendition): bool
    {
        return $user->agency_id === $rendition->agency_id && $this->ledgers->view($user, $rendition->ledger);
    }

    public function download(User $user, Rendition $rendition): bool
    {
        return $this->view($user, $rendition);
    }

    public function retry(User $user, Rendition $rendition): bool
    {
        return $user->agency_id === $rendition->agency_id
            && $user->allows(Permission::ManageLedgers)
            && $rendition->status === RenditionStatus::Failed;
    }
}
