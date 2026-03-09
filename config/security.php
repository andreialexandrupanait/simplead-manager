<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WordPress Version Thresholds
    |--------------------------------------------------------------------------
    |
    | Versions below 'recommended' are flagged as 'high' severity.
    | Versions below 'minimum' are flagged as 'critical' severity.
    |
    */
    'wordpress' => [
        'minimum_version' => env('WP_MIN_VERSION', '6.0'),
        'recommended_version' => env('WP_RECOMMENDED_VERSION', '6.4'),
    ],
];
