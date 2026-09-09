<?php

/**
 * Password broker outcomes. `token` and `user` are deliberately worded the
 * same way — an expired link and an address with no account both read as "that
 * link is no longer good", so neither response reveals whether the address is
 * registered. PasswordResetLinkController applies the same policy to the
 * request side.
 */

return [
    'reset' => 'Your password is set. Sign in with it.',
    'sent' => 'If that address is registered, a reset link is on its way.',
    'throttled' => 'Wait a moment before asking for another link.',
    'token' => 'That reset link has expired. Ask for a new one.',
    'user' => 'That reset link is no longer valid. Ask for a new one.',
];
