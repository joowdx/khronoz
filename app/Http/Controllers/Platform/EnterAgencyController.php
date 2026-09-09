<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use Illuminate\Http\RedirectResponse;

class EnterAgencyController extends Controller
{
    /**
     * A platform user "enters" $agency: it becomes their tenant for the rest
     * of the session (the `agency` key SetTenant reads), without them ever
     * leaving the platform agency they actually belong to.
     */
    public function store(Agency $agency): RedirectResponse
    {
        session(['agency' => $agency->id]);

        return redirect()->route('dashboard')->with('success', "Entered {$agency->name}");
    }

    /** Leave the entered agency, returning to the platform agency itself. */
    public function destroy(): RedirectResponse
    {
        session()->forget('agency');

        return redirect()->route('dashboard');
    }
}
