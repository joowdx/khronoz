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

    private function discard(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
