<?php

namespace App\Http\Controllers\Settings;

use App\Actions\RequestEmailChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreEmailNotificationRequest;
use Illuminate\Http\RedirectResponse;

class EmailNotificationController extends Controller
{
    public function store(StoreEmailNotificationRequest $request, RequestEmailChange $change): RedirectResponse
    {
        abort_unless($email = $request->user()->pending_email, 404);
        $change->handle($request->user(), $email);

        return back()->with('success', 'A new verification link has been sent.');
    }
}
