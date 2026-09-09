<?php

/**
 * Form-level authentication failures: they belong to no single field, so the
 * login screen renders them as the banner above the fields and no control's
 * border changes. Each one says what happened and how to get out of it.
 */

return [
    /*
     * One message for a wrong password and for an email that has no account,
     * so a login attempt cannot be used to discover which addresses exist —
     * see LoginRequest::authenticate(). The login page turns the closing
     * phrase into the link to the reset flow.
     */
    'failed' => 'That email and password do not match. Check both and try again, or reset your password.',

    'password' => 'That password is not right.',

    'throttle' => 'Too many attempts. Try again in :seconds seconds.',

    /*
     * An invited account exists but has never been claimed. Naming the fix
     * matters more than naming the cause: the emailed link is the only way in.
     */
    'invited' => 'This invitation has not been accepted yet. Use the link in your email, or ask your HR office to send it again.',
];
