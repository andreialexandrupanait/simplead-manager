<?php

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['X-Signature', 'X-Timestamp', 'Content-Type', 'Accept'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
