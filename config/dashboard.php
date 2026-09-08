<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Operational Dashboard Access
    |--------------------------------------------------------------------------
    |
    | Email addresses allowed to open /horizon and /telescope outside the local
    | environment, as a comma separated list. Everyone is allowed locally.
    | See App\Support\Dashboard for the gate itself.
    |
    */

    'emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DASHBOARD_EMAILS')),
    ))),

];
