<?php

namespace App\Console\Commands;

use App\Support\AppRoleGrants;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Re-issues khronoz_app's row privileges. A fresh install gets them from
 * 0000_00_00_000001_prepare_database automatically; run this by hand as a
 * post-deploy step after the owner role is rotated (README, Database roles),
 * because a later migration's default privileges follow whichever role ran
 * it, not the original owner that first granted khronoz_app access.
 */
#[Signature('db:grant')]
#[Description("Re-issue khronoz_app's row privileges on every table and sequence")]
class GrantAppRolePrivileges extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        AppRoleGrants::apply();

        $this->components->info('Granted khronoz_app row privileges on every present and future table and sequence.');
    }
}
