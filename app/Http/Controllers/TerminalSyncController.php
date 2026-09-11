<?php

namespace App\Http\Controllers;

use App\Actions\ImportTimelogs;
use App\Http\Requests\ImportTimelogsRequest;
use App\Jobs\RecomputeWorkdays;
use App\Models\Terminal;
use App\Support\AttlogParser;
use Illuminate\Http\RedirectResponse;
use Throwable;

class TerminalSyncController extends Controller
{
    /**
     * Ingest an uploaded attlog export.
     *
     * The uploaded file is **deleted when the run finishes, whatever the
     * outcome**. It holds device user ids and punch times for everybody in the
     * agency, and the predecessor left every import it ever ran sitting in the
     * server's temp directory indefinitely. Only the filename survives, on the
     * `syncs` row.
     *
     * `$terminal` is resolved through AgencyScope by route binding, so another
     * agency's terminal is a 404 rather than something this endpoint can write
     * to.
     */
    public function store(ImportTimelogsRequest $request, Terminal $terminal, ImportTimelogs $import): RedirectResponse
    {
        $file = $request->file('file');
        $path = $file->getRealPath();

        try {
            [$sync, $pairs] = $import->handle(
                $terminal,
                $path,
                $file->getClientOriginalName(),
                $request->string('layout', AttlogParser::LAYOUT_STANDARD)->toString(),
            );
        } catch (Throwable) {
            $this->discard($path);

            return back()->with('error', 'The file could not be read. Nothing was imported.');
        }

        $this->discard($path);

        RecomputeWorkdays::dispatchFor($pairs);

        if ($sync->accepted === 0 && $sync->duplicates > 0) {
            return back()->with('success', "Nothing new — all {$sync->duplicates} records were already imported.");
        }

        $message = "Imported {$sync->accepted} of {$sync->received} records.";

        if ($sync->rejected > 0) {
            $message .= " {$sync->rejected} line(s) could not be read and were skipped.";
        }

        return back()->with('success', $message);
    }

    /** Remove the upload. It carries everybody's device ids and punch times. */
    private function discard(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
