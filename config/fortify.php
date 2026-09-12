<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    'username' => 'email',
    'email' => 'email',
    'home' => '/dashboard',
    'views' => false,
    'features' => [Features::twoFactorAuthentication(['confirm' => true])],
    'passkeys' => [
        'relying_party_id' => parse_url(config('app.url'), PHP_URL_HOST),
        'allowed_origins' => [config('app.url')],
        'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET', config('app.key')),
        'timeout' => 60000,
    ],
];
