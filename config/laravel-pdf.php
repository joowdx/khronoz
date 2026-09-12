<?php

return [
    'driver' => env('LARAVEL_PDF_DRIVER', 'gotenberg'),

    'gotenberg' => [
        'url' => env('GOTENBERG_URL', 'http://127.0.0.1:43000'),
        'username' => env('GOTENBERG_USERNAME'),
        'password' => env('GOTENBERG_PASSWORD'),
    ],

    'cache' => [
        'automatic' => false,
    ],
];
