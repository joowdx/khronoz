<?php

namespace App\Http\Controllers\Settings;

use App\Actions\RequestEmailChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DestroyEmailRequest;
use App\Http\Requests\Settings\StoreEmailRequest;
use Illuminate\Http\RedirectResponse;

class EmailController extends Controller
{
    public function store(StoreEmailRequest $request, RequestEmailChange $change): RedirectResponse
    {
        $change->handle($request->user(), $request->validated('email'));

        return back()->with('success', 'Check your new email address for a verification link.');
    }

    public function destroy(DestroyEmailRequest $request): RedirectResponse
    {
        $request->user()->forceFill([
            'pending_email' => null, 'pending_email_token' => null, 'pending_email_expires_at' => null,
        ])->save();

        return back()->with('success', 'Email change cancelled.');
    }
}
