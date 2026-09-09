<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     *
     * EmailVerificationRequest::authorize() already checked the {id}/{hash}
     * pair against the signed-in user before this runs, and fulfill() itself
     * is idempotent, so an already-verified user simply redirects through
     * without a second markEmailAsVerified() write or Verified event.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->route('dashboard')->with('success', 'Email verified.');
    }
}
