<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreConfirmationRequest;
use App\Support\Authentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConfirmationController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('auth/confirm', [
            'destination' => Authentication::destination($request->query('return')),
        ]);
    }

    public function store(StoreConfirmationRequest $request): RedirectResponse
    {
        Authentication::confirm($request);

        return redirect()->to(Authentication::destination($request->validated('destination')));
    }
}
