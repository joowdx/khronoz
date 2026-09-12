<?php

namespace App\Http\Controllers;

use App\Enums\CadenceKind;
use App\Enums\Permission;
use App\Http\Requests\StoreCadenceRequest;
use App\Http\Requests\UpdateCadenceRequest;
use App\Http\Resources\CadenceResource;
use App\Models\Agency;
use App\Models\Cadence;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CadenceController extends Controller
{
    public function index(): Response
    {
        Gate::authorize(Permission::ManageAgency->value);

        return Inertia::render('cadences/index', [
            'cadences' => CadenceResource::collection(Cadence::orderBy('retired_at')->orderBy('name')->get())->resolve(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize(Permission::ManageAgency->value);

        return Inertia::render('cadences/create', ['kinds' => $this->kinds()]);
    }

    public function edit(Cadence $cadence): Response
    {
        Gate::authorize(Permission::ManageAgency->value);

        return Inertia::render('cadences/edit', [
            'cadence' => CadenceResource::make($cadence)->resolve(), 'kinds' => $this->kinds(),
        ]);
    }

    public function store(StoreCadenceRequest $request, Tenant $tenant): RedirectResponse
    {
        DB::transaction(function () use ($request, $tenant): void {
            Agency::whereKey($tenant->id())->lockForUpdate()->firstOrFail();
            if ($request->boolean('preferred')) {
                Cadence::where('preferred', true)->update(['preferred' => false]);
            }
            $data = $request->validated();
            $data['rules'] = (object) (isset($data['rules']['starts']) ? ['starts' => array_map('intval', $data['rules']['starts'])] : []);
            Cadence::create($data);
        });

        return redirect()->route('cadences.index')->with('success', 'Cadence added.');
    }

    public function update(UpdateCadenceRequest $request, Cadence $cadence, Tenant $tenant): RedirectResponse
    {
        DB::transaction(function () use ($request, $cadence, $tenant): void {
            Agency::whereKey($tenant->id())->lockForUpdate()->firstOrFail();
            abort_if($cadence->fresh()->retired_at !== null, 409, 'This cadence is retired.');
            if ($request->boolean('preferred')) {
                Cadence::where('preferred', true)->whereKeyNot($cadence->id)->update(['preferred' => false]);
            }
            $data = $request->validated();
            $data['rules'] = (object) (isset($data['rules']['starts']) ? ['starts' => array_map('intval', $data['rules']['starts'])] : []);
            $cadence->update($data);
        });

        return redirect()->route('cadences.index')->with('success', 'Cadence updated.');
    }

    private function kinds(): array
    {
        return array_map(fn (CadenceKind $kind): array => ['value' => $kind->value, 'label' => ucfirst($kind->value)], CadenceKind::cases());
    }
}
