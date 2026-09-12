<?php

namespace App\Console\Commands;

use App\Support\AppRoleGrants;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('db:grant')]
#[Description("Re-issue the app role's row privileges on every table and sequence")]
class GrantAppRolePrivileges extends Command
{
    public function handle(): void
    {
        AppRoleGrants::apply();

        $this->components->info('Granted the app role row privileges on every present and future table and sequence.');
    }
}
