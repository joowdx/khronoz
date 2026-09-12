<?php

namespace App\Http\Controllers;

use App\Actions\RecordAcceptance;
use App\Http\Requests\StoreAcceptanceRequest;
use App\Support\Legal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Head\Facades\Head;

class AcceptanceController extends Controller
{
    public function create(Request $request, Legal $legal): Response|RedirectResponse
    {
        abort_if(app()->isProduction() && ! $legal->isPublished(), 503, 'Legal documents are awaiting publication.');

        if ($legal->isAcceptedBy($request->user())) {
            return redirect()->route('dashboard');
        }

        Head::title('Review the agreements');

        return Inertia::render('legal/acceptance', ['documents' => $legal->current()]);
    }

    public function store(StoreAcceptanceRequest $request, Legal $legal, RecordAcceptance $record): RedirectResponse
    {
        abort_if(app()->isProduction() && ! $legal->isPublished(), 503, 'Legal documents are awaiting publication.');
        $record->handle($request->user(), $request->validated('documents'));

        $intended = $request->session()->pull('legal.intended');
        $destination = is_string($intended) && str_starts_with($intended, '/') && ! str_starts_with($intended, '//')
            && ! str_contains($intended, '\\') && ! preg_match('/[\x00-\x20]/', $intended)
            ? $intended : route('dashboard');

        return redirect()->to($destination)->with('success', 'Your acknowledgments have been recorded.');
    }
}
