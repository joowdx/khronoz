<?php

namespace App\Console\Commands;

use App\Actions\ImportTimelogs as ImportTimelogsAction;
use App\Jobs\RecomputeWorkdays;
use App\Models\Agency;
use App\Models\Scopes\AgencyScope;
use App\Models\Terminal;
use App\Support\AttlogParser;
use App\Tenancy\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Import a device's attlog export from the command line: the entry point that needs no browser,
 * which is deliberate, because ingestion that reads `Auth::user()` can only run inside an
 * authenticated web request. Bulk recovery, a one-off backfill and a cron job all want this.
 */
#[Signature('timelogs:import {terminal : The terminal code or id} {path : Path to the attlog export} {--layout=standard : Column layout — standard (uid,time,state,mode) or device (uid,time,device,state,mode)} {--chunk=500 : Rows per insert}')]
#[Description("Import a device's attlog export into timelogs")]
class ImportTimelogs extends Command
{
    public function handle(ImportTimelogsAction $import, Tenant $tenant): int
    {
        $path = (string) $this->argument('path');

        if (! is_readable($path)) {
            $this->components->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $terminal = Terminal::withoutGlobalScope(AgencyScope::class)
            ->where('id', $this->argument('terminal'))
            ->orWhere('code', $this->argument('terminal'))
            ->first();

        if ($terminal === null) {
            $this->components->error("No terminal matches {$this->argument('terminal')}.");

            return self::FAILURE;
        }

        $tenant->set(Agency::findOrFail($terminal->agency_id));

        $layout = (string) $this->option('layout');

        if (! in_array($layout, [AttlogParser::LAYOUT_STANDARD, AttlogParser::LAYOUT_DEVICE], true)) {
            $this->components->error("Unknown layout {$layout}.");

            return self::FAILURE;
        }

        [$sync, $pairs] = $import->handle(
            $terminal,
            $path,
            basename($path),
            $layout,
            (int) $this->option('chunk'),
        );

        RecomputeWorkdays::dispatchFor($pairs);

        $this->components->twoColumnDetail('Terminal', "{$terminal->name} ({$terminal->code})");
        $this->components->twoColumnDetail('Received', (string) $sync->received);
        $this->components->twoColumnDetail('Accepted', (string) $sync->accepted);
        $this->components->twoColumnDetail('Duplicates', (string) $sync->duplicates);
        $this->components->twoColumnDetail('Rejected', (string) $sync->rejected);

        if ($sync->earliest !== null) {
            $this->components->twoColumnDetail('Span', "{$sync->earliest} — {$sync->latest}");
        }

        if ($sync->rejected > 0) {
            $this->components->warn("{$sync->rejected} line(s) could not be read and were skipped.");
        }

        $this->components->info("Imported into sync {$sync->id}.");

        return self::SUCCESS;
    }
}
