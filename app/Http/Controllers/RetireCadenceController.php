<?php

namespace App\Http\Controllers;

use App\Http\Requests\RetireCadenceRequest;
use App\Models\Agency;
use App\Models\Cadence;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class RetireCadenceController extends Controller
{
    public function __invoke(RetireCadenceRequest $request, Cadence $cadence, Tenant $tenant): RedirectResponse
    {
        DB::transaction(function () use ($cadence, $tenant): void {
            Agency::whereKey($tenant->id())->lockForUpdate()->firstOrFail();
            $cadence->update(['retired_at' => now(), 'preferred' => false]);
        });

        return redirect()->route('cadences.index')->with('success', 'Cadence retired.');
    }
}
