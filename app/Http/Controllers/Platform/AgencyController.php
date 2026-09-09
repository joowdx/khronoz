<?php

namespace App\Http\Controllers\Platform;

use App\Actions\CreateAgency;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreAgencyRequest;
use App\Http\Requests\Platform\UpdateAgencyRequest;
use App\Http\Resources\AgencyResource;
use App\Models\Agency;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AgencyController extends Controller
{
    /** List every agency; NotPlatformScope already hides the platform row. */
    public function index(): Response
    {
        return Inertia::render('platform/agencies/index', [
            'agencies' => AgencyResource::collection(Agency::orderBy('name')->get())->resolve(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('platform/agencies/create');
    }

    public function store(StoreAgencyRequest $request, CreateAgency $create): RedirectResponse
    {
        $agency = $create->handle($request->validated());

        return redirect()->route('platform.agencies.index')->with('success', "Agency {$agency->code} created.");
    }

    public function edit(Agency $agency): Response
    {
        return Inertia::render('platform/agencies/edit', [
            'agency' => AgencyResource::make($agency)->resolve(),
        ]);
    }

    public function update(UpdateAgencyRequest $request, Agency $agency): RedirectResponse
    {
        $agency->update($request->validated());

        return redirect()->route('platform.agencies.index')->with('success', "Agency {$agency->code} updated.");
    }
}
