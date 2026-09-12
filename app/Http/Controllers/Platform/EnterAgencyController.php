<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use Illuminate\Http\RedirectResponse;

class EnterAgencyController extends Controller
{
    public function store(Agency $agency): RedirectResponse
    {
        session(['agency' => $agency->id]);

        return redirect()->route('dashboard')->with('success', "Entered {$agency->name}");
    }

    public function destroy(): RedirectResponse
    {
        session()->forget('agency');

        return redirect()->route('dashboard');
    }
}
